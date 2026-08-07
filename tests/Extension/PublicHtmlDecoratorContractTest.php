<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Markdown\Tests\Extension;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\Html\HtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;
use Alto\Markdown\Extension\Mention\MentionDefinition;
use Alto\Markdown\Extension\Mention\MentionExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Tests\Extension\Fixture\PublicMarkExtension;
use PHPUnit\Framework\TestCase;

final class PublicHtmlDecoratorContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::disable();
        Instrumentation::reset();
    }

    public function testDecoratesNativeBlockAndInlineKindsInBothRenderLanes(): void
    {
        $factory = Markdown::commonmark()->with(new ContextRecordingDecoratorExtension());
        $markdown = "# Hello\n\nRead [Alto](https://example.com/docs \"Docs\").\n";
        $expected = "<h1 data-level=\"1\">Hello</h1>\n"
            ."<p>Read <a data-destination=\"https://example.com/docs\" href=\"https://example.com/docs\" title=\"Docs\">Alto</a>.</p>\n";

        self::assertSame($expected, $factory->toHtml($markdown));
        self::assertSame($expected, $factory->fromString($markdown)->toHtml());
    }

    public function testCompilesPriorityThenRegistrationOrder(): void
    {
        $factory = Markdown::commonmark()->with(
            new OrderedDecoratorExtension('middle', 0),
            new OrderedDecoratorExtension('earlier-at-priority', 10),
            new OrderedDecoratorExtension('first', -10),
            new OrderedDecoratorExtension('later-at-priority', 10),
        );

        self::assertSame(
            "later-at-priority(earlier-at-priority(middle(first(<hr />\n))))",
            $factory->toHtml("---\n"),
        );
    }

    public function testLinkLikeDecoratorReceivesDeclaredCustomLinkSemantics(): void
    {
        $decorator = new RecordingDecorator();
        $factory = Markdown::commonmark()->with(
            new MentionExtension(MentionDefinition::links(
                'user',
                '@',
                '[a-z]+',
                'https://profiles.test/%s',
            )),
            new LinkLikeRecordingDecoratorExtension($decorator),
        );

        Instrumentation::reset();
        self::assertSame(
            "<p>Hello <a href=\"https://profiles.test/ada\">@ada</a>.</p>\n",
            $factory->toHtml("Hello @ada.\n"),
        );
        self::assertSame(1, Instrumentation::$htmlDecoratorInvocations);
        self::assertSame(0, Instrumentation::$fusedInlineFallbacks);
        self::assertCount(1, $decorator->contexts);
        self::assertSame('mention:user', $decorator->contexts[0]->kind);
        self::assertSame('@ada', $decorator->contexts[0]->source());
        self::assertSame(6, $decorator->contexts[0]->range?->startOffset);
        self::assertSame('https://profiles.test/ada', $decorator->contexts[0]->string('destination'));
        self::assertNull($decorator->contexts[0]->attribute('title'));
    }

    public function testHeadingDecoratorReceivesOneSourceWideSlugContract(): void
    {
        $decorator = new RecordingDecorator();
        $factory = Markdown::commonmark()->with(new HeadingRoleRecordingDecoratorExtension($decorator));

        self::assertSame(
            "<h1>Repeat</h1>\n<h2>Repeat</h2>\n",
            $factory->toHtml("# Repeat\n\nRepeat\n------\n"),
        );
        self::assertCount(2, $decorator->contexts);
        self::assertSame('atx-heading', $decorator->contexts[0]->kind);
        self::assertSame('repeat', $decorator->contexts[0]->string('slug'));
        self::assertSame('setext-heading', $decorator->contexts[1]->kind);
        self::assertSame('repeat-1', $decorator->contexts[1]->string('slug'));
        self::assertSame(2, $decorator->contexts[1]->int('level'));
    }

    public function testContextExposesOriginalRangeSourceAndTypedSemantics(): void
    {
        $heading = new RecordingDecorator();
        $link = new RecordingDecorator();
        $factory = Markdown::commonmark()->with(new RecordingDecoratorExtension($heading, $link));
        $markdown = "# Hello\n\n[A](https://example.com \"Title\")\n";

        $factory->toHtml($markdown);

        self::assertCount(1, $heading->contexts);
        self::assertSame('atx-heading', $heading->contexts[0]->kind);
        self::assertSame(0, $heading->contexts[0]->range?->startOffset);
        self::assertSame('# Hello', $heading->contexts[0]->source());
        self::assertSame(1, $heading->contexts[0]->int('level'));

        self::assertCount(1, $link->contexts);
        self::assertSame('link', $link->contexts[0]->kind);
        self::assertSame('https://example.com', $link->contexts[0]->string('destination'));
        self::assertSame('Title', $link->contexts[0]->string('title'));
        self::assertStringStartsWith('[A]', $link->contexts[0]->source());
    }

    public function testContextEscapeHelpersRespectTheActiveHtmlPolicy(): void
    {
        $factory = Markdown::commonmark()->with(new EscapingLinkDecoratorExtension());

        self::assertSame(
            "<p><span data-url=\"\">x &amp; y</span></p>\n",
            $factory->toHtml('[x & y](javascript:alert(1))'."\n"),
        );
    }

    public function testCuratedPolicySanitizesDecoratorOutputOnceAtTheEnd(): void
    {
        if (!class_exists(\Dom\HTMLDocument::class)) {
            self::markTestSkipped('The curated policy requires the DOM HTML5 parser.');
        }

        $factory = Markdown::commonmark()->with(new UnsafeDecoratorExtension());
        $unsafe = $factory->toHtml("---\n");
        $curated = $factory->toHtml(
            "---\n",
            renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
        );

        self::assertStringContainsString('<script>', $unsafe);
        self::assertStringNotContainsString('<script>', $curated);
        self::assertStringContainsString('<hr>', $curated);
    }

    public function testDeepContainerDecorationUsesTheHeapBoundRenderer(): void
    {
        $depth = 550;
        $factory = Markdown::commonmark()->with(new OrderedDecoratorExtension('decorated', 0, 'block-quote'));
        $html = $factory->toHtml(
            str_repeat('> ', $depth)."text\n",
            new ParseOptions(maxNestingDepth: 600),
        );

        self::assertSame($depth, substr_count($html, 'decorated('));
        self::assertStringContainsString('<p>text</p>', $html);
    }

    public function testRejectsInvalidDefinitionsAndNonNativeTargets(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('unknown or non-native node kind "custom:leaf"');

        Markdown::commonmark()->with(new OrderedDecoratorExtension('x', 0, 'custom:leaf'));
    }

    public function testRejectsAKindMissingFromTheSelectedProfile(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('unknown or non-native node kind "strikethrough"');

        Markdown::commonmark()->with(new OrderedDecoratorExtension('x', 0, 'strikethrough'));
    }

    public function testRejectsInvalidDefinitionValuesFromAnExtension(): void
    {
        $extension = self::createStub(HtmlDecoratorExtensionInterface::class);
        $extension->method('name')->willReturn('invalid');
        $extension->method('htmlDecorators')->willReturn([new \stdClass()]);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('htmlDecorators() must yield');

        Markdown::commonmark()->with($extension);
    }

    public function testDefinitionRejectsAnInvalidKindName(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('HTML decorator kind "Not Valid"');

        HtmlDecoratorDefinition::node('Not Valid', new OrderDecorator('x'));
    }

    public function testContextRejectsInvalidAndWronglyTypedAttributes(): void
    {
        $context = new HtmlNodeOutputContext(
            'link',
            null,
            '',
            HtmlPolicy::safe(),
            ['destination' => 3],
        );

        try {
            $context->attribute('missing');
            self::fail('A missing semantic attribute must fail.');
        } catch (InvalidMarkdownArgumentException $exception) {
            self::assertStringContainsString('is not defined', $exception->getMessage());
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('is not a string');
        $context->string('destination');
    }

    public function testInactiveProfilesAndUnmatchedKindsDispatchNothing(): void
    {
        Instrumentation::measure();
        Markdown::commonmark()->toHtml("# Heading\n\nRich *text*.\n");
        self::assertSame(0, Instrumentation::$htmlDecoratorInvocations);

        Instrumentation::measure();
        $factory = Markdown::commonmark()->with(new OrderedDecoratorExtension('unused', 0, 'thematic-break'));
        $factory->toHtml("# Heading\n\nRich *text*.\n");
        self::assertSame(0, Instrumentation::$htmlDecoratorInvocations);

        Instrumentation::measure();
        $factory->toHtml("---\n");
        self::assertSame(1, Instrumentation::$htmlDecoratorInvocations);
    }

    public function testEveryNativeKindKeepsItsHtmlWhenDecoratedWithANoOp(): void
    {
        $markdown = <<<'MARKDOWN'
            # ATX

            Setext
            ------

            Paragraph with
            a soft break, then a backslash\
            hard break, `code`, *emphasis*, **strong**, ~~strike~~,
            [link](/docs "Docs"), ![image
            alt](/image.png "Image"),
            <https://example.com>, <span>raw inline</span>, and ^^custom^^.

            ---

                indented code

            ```php
            fenced code
            ```

            > quote

            - [x] checked
            - [ ] unchecked
            - ordinary

            | Name |
            | --- |
            | Alto |

            > [!NOTE]
            > Alert

            <div>
            raw block
            </div>
            MARKDOWN;
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());
        $plain = Markdown::github()->with(new PublicMarkExtension());
        $decorated = $plain->with(new AllNativeDecoratorExtension());
        $expected = $plain->toHtml($markdown, renderOptions: $options);

        Instrumentation::measure();
        self::assertSame($expected, $decorated->toHtml($markdown, renderOptions: $options));
        self::assertSame($expected, $decorated->fromString($markdown)->toHtml($options));
        self::assertGreaterThan(40, Instrumentation::$htmlDecoratorInvocations);

        self::assertSame(
            $plain->toHtml("**foo **bar****\n", renderOptions: $options),
            $decorated->toHtml("**foo **bar****\n", renderOptions: $options),
        );
    }

    public function testContextValidatesAllScalarAttributeAccess(): void
    {
        $context = new HtmlNodeOutputContext(
            'list',
            null,
            '',
            HtmlPolicy::safe(),
            ['enabled' => true, 'count' => 2],
        );

        self::assertTrue($context->bool('enabled'));
        self::assertSame(2, $context->int('count'));

        try {
            $context->bool('count');
            self::fail('A non-boolean attribute must fail.');
        } catch (InvalidMarkdownArgumentException $exception) {
            self::assertStringContainsString('is not a boolean', $exception->getMessage());
        }

        try {
            $context->int('enabled');
            self::fail('A non-integer attribute must fail.');
        } catch (InvalidMarkdownArgumentException $exception) {
            self::assertStringContainsString('is not an integer', $exception->getMessage());
        }
    }

    public function testContextRejectsInvalidAttributeStorage(): void
    {
        try {
            new \ReflectionClass(HtmlNodeOutputContext::class)->newInstance(
                'text',
                null,
                '',
                HtmlPolicy::safe(),
                [0 => 'invalid'],
            );
            self::fail('A non-string attribute name must fail.');
        } catch (InvalidMarkdownArgumentException $exception) {
            self::assertStringContainsString('names must be strings', $exception->getMessage());
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('must be a boolean, integer, string, or null');

        new \ReflectionClass(HtmlNodeOutputContext::class)->newInstance(
            'text',
            null,
            '',
            HtmlPolicy::safe(),
            ['invalid' => []],
        );
    }
}

final readonly class ContextRecordingDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function name(): string
    {
        return 'context-output';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::node('atx-heading', new HeadingAttributeDecorator());
        yield HtmlDecoratorDefinition::node('link', new LinkAttributeDecorator());
    }
}

final readonly class HeadingAttributeDecorator implements HtmlNodeDecorator
{
    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        return str_replace(
            '<h'.$context->int('level'),
            '<h'.$context->int('level').' data-level="'.$context->int('level').'"',
            $html,
        );
    }
}

final readonly class LinkAttributeDecorator implements HtmlNodeDecorator
{
    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        return str_replace(
            '<a ',
            '<a data-destination="'.$context->escapeUrl($context->string('destination')).'" ',
            $html,
        );
    }
}

final readonly class OrderedDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function __construct(
        private string $label,
        private int $priority,
        private string $kind = 'thematic-break',
    ) {
    }

    public function name(): string
    {
        return 'order-'.$this->label;
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::node($this->kind, new OrderDecorator($this->label), $this->priority);
    }
}

final readonly class OrderDecorator implements HtmlNodeDecorator
{
    public function __construct(private string $label)
    {
    }

    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        return $this->label.'('.$html.')';
    }
}

final class RecordingDecorator implements HtmlNodeDecorator
{
    /**
     * @var list<HtmlNodeOutputContext>
     */
    public array $contexts = [];

    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        $this->contexts[] = $context;

        return $html;
    }
}

final readonly class RecordingDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function __construct(
        private RecordingDecorator $heading,
        private RecordingDecorator $link,
    ) {
    }

    public function name(): string
    {
        return 'recording';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::node('atx-heading', $this->heading);
        yield HtmlDecoratorDefinition::node('link', $this->link);
    }
}

final readonly class LinkLikeRecordingDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function __construct(private RecordingDecorator $decorator)
    {
    }

    public function name(): string
    {
        return 'recording-link-like';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::linkLike($this->decorator);
    }
}

final readonly class HeadingRoleRecordingDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function __construct(private RecordingDecorator $decorator)
    {
    }

    public function name(): string
    {
        return 'recording-heading';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::heading($this->decorator);
    }
}

final readonly class EscapingLinkDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function name(): string
    {
        return 'escaping-link';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::node('link', new EscapingLinkDecorator());
    }
}

final readonly class EscapingLinkDecorator implements HtmlNodeDecorator
{
    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        return '<span data-url="'.$context->escapeUrl($context->string('destination')).'">'
            .$context->escapeText('x & y')
            .'</span>';
    }
}

final readonly class UnsafeDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function name(): string
    {
        return 'unsafe-output';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::node('thematic-break', new UnsafeDecorator());
    }
}

final readonly class UnsafeDecorator implements HtmlNodeDecorator
{
    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        return '<script>alert(1)</script>'.$html;
    }
}

final readonly class AllNativeDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function name(): string
    {
        return 'all-native-output';
    }

    public function htmlDecorators(): iterable
    {
        foreach ([
            'paragraph',
            'atx-heading',
            'setext-heading',
            'indented-code',
            'fenced-code',
            'html-block',
            'block-quote',
            'list',
            'list-item',
            'thematic-break',
            'gfm:table',
            'github:alert',
            'text',
            'soft-break',
            'hard-break',
            'code-span',
            'emphasis',
            'strong',
            'link',
            'image',
            'autolink',
            'html-inline',
            'strikethrough',
        ] as $kind) {
            yield HtmlDecoratorDefinition::node($kind, new NoOpDecorator());
        }
    }
}

final readonly class NoOpDecorator implements HtmlNodeDecorator
{
    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        return $html;
    }
}
