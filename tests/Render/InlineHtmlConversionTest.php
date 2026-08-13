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

namespace Alto\Markdown\Tests\Render;

use Alto\Markdown\Exception\InlineCountLimitException;
use Alto\Markdown\Exception\SourceSizeLimitException;
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
use Alto\Markdown\Render\HtmlSanitizer;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Tests\Extension\Fixture\PublicMarkExtension;
use PHPUnit\Framework\TestCase;

final class InlineHtmlConversionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testConvertsOneInlineFragmentWithoutAWrapperOrAddedNewline(): void
    {
        self::assertSame(
            'Hello <em>world</em>.',
            Markdown::commonmark()->toInlineHtml('Hello *world*.'),
        );
    }

    public function testBlockSyntaxRemainsLiteral(): void
    {
        $source = "# Heading\n\n- first\n- second\n";

        self::assertSame(
            "# Heading\n\n- first\n- second",
            Markdown::commonmark()->toInlineHtml($source),
        );
    }

    public function testReferenceDefinitionsAreNotExtracted(): void
    {
        $source = "[label][ref]\n\n[ref]: /target\n";

        self::assertSame(
            "[label][ref]\n\n[ref]: /target",
            Markdown::commonmark()->toInlineHtml($source),
        );
    }

    public function testNormalizesPhysicalLineEndingsAndRendersHardBreaks(): void
    {
        self::assertSame(
            "first<br />\nsecond\nthird",
            Markdown::commonmark()->toInlineHtml("first  \r\nsecond\rthird\n"),
        );
    }

    public function testIgnoresALeadingBomAndTerminalLineEnding(): void
    {
        self::assertSame('', Markdown::commonmark()->toInlineHtml(''));
        self::assertSame('', Markdown::commonmark()->toInlineHtml("\xEF\xBB\xBF"));
        self::assertSame(
            'Hello',
            Markdown::commonmark()->toInlineHtml("\xEF\xBB\xBFHello\n"),
        );
    }

    public function testUsesInlineExtensionsAndQualifiedLinkSemantics(): void
    {
        $factory = Markdown::commonmark()->with(
            new PublicMarkExtension(),
            new MentionExtension(
                MentionDefinition::links('user', '@', '[a-z]+', 'https://example.com/users/%s'),
            ),
        );

        self::assertSame(
            '<mark data-label="marked">marked</mark> '
            . '<a href="https://example.com/users/ada">@ada</a>',
            $factory->toInlineHtml('^^marked^^ @ada'),
        );
        self::assertSame(
            '<a href="/outer">Hi @ada</a>',
            $factory->toInlineHtml('[Hi @ada](/outer)'),
        );
    }

    public function testAppliesInlineNativeDecoratorsWithOriginalRanges(): void
    {
        $decorator = new InlineRangeDecorator();
        $factory = Markdown::commonmark()->with(new InlineRangeDecoratorExtension($decorator));

        self::assertSame(
            'Read <a data-range="5:21" href="/target">label</a>',
            $factory->toInlineHtml('Read [label](/target)', new ParseOptions()),
        );
        self::assertSame('link', $decorator->kind);
        self::assertSame('[label](/target)', $decorator->source);
    }

    public function testUsesRawHtmlAndUrlPolicies(): void
    {
        $source = '<b>raw</b> [x](javascript:alert(1))';
        $factory = Markdown::commonmark();

        self::assertSame(
            '&lt;b&gt;raw&lt;/b&gt; <a href="">x</a>',
            $factory->toInlineHtml($source),
        );
        self::assertSame(
            '<b>raw</b> <a href="javascript:alert(1)">x</a>',
            $factory->toInlineHtml(
                $source,
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::spec()),
            ),
        );
    }

    public function testAppliesTheFinalFragmentSanitizerExactlyOnce(): void
    {
        $sanitizer = new InlineRecordingSanitizer();
        $policy = HtmlPolicy::safe()->withSanitizer($sanitizer);

        self::assertSame(
            '<sanitized><i>raw</i></sanitized>',
            Markdown::commonmark()->toInlineHtml(
                '<i>raw</i>',
                renderOptions: new RenderOptions(htmlPolicy: $policy),
            ),
        );
        self::assertSame(1, $sanitizer->calls);
    }

    public function testHonorsSourceAndInlineBudgets(): void
    {
        $factory = Markdown::commonmark();

        try {
            $factory->toInlineHtml('12345', new ParseOptions(maxSourceBytes: 4));
            self::fail('Expected the source byte budget to reject the fragment.');
        } catch (SourceSizeLimitException $exception) {
            self::assertSame(5, $exception->sourceBytes);
        }

        $this->expectException(InlineCountLimitException::class);
        $factory->toInlineHtml('**strong**', new ParseOptions(maxInlineCount: 1));
    }

    public function testSkipsBlockParsingAndDocumentConstruction(): void
    {
        Instrumentation::reset();

        self::assertSame('Rich <strong>text</strong>.', Markdown::commonmark()->toInlineHtml('Rich **text**.'));
        self::assertSame(0, Instrumentation::$syntaxParses);
        self::assertSame(0, Instrumentation::$documentWorkspaces);
        self::assertSame(1, Instrumentation::$fusedInlineRenders);
    }
}

final class InlineRangeDecorator implements HtmlNodeDecorator
{
    public ?string $kind = null;

    public ?string $source = null;

    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        $this->kind = $context->kind;
        $this->source = $context->source();
        $range = $context->range;

        return str_replace(
            '<a ',
            '<a data-range="' . $range?->startOffset . ':' . $range?->endOffset . '" ',
            $html,
        );
    }
}

final readonly class InlineRangeDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function __construct(
        private InlineRangeDecorator $decorator,
    ) {}

    public function name(): string
    {
        return 'inline-range';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::node('link', $this->decorator);
    }
}

final class InlineRecordingSanitizer implements HtmlSanitizer
{
    public int $calls = 0;

    public function sanitize(string $html, HtmlPolicy $policy): string
    {
        ++$this->calls;

        return '<sanitized>' . $html . '</sanitized>';
    }

    public function cacheKey(): string
    {
        return 'inline-recording-v1';
    }
}
