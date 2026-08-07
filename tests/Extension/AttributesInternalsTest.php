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

use Alto\Markdown\Extension\Attributes\AttributeListParser;
use Alto\Markdown\Extension\Attributes\AttributesBlockDecorator;
use Alto\Markdown\Extension\Attributes\AttributesCatalog;
use Alto\Markdown\Extension\Attributes\AttributeSet;
use Alto\Markdown\Extension\Attributes\AttributesHtmlInjector;
use Alto\Markdown\Extension\Attributes\AttributesInlineOutput;
use Alto\Markdown\Extension\Attributes\AttributesPolicy;
use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;
use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributesInternalsTest extends TestCase
{
    public function testParserSupportsShortcutsQuotedValuesBooleansAndPrefixes(): void
    {
        $policy = new AttributesPolicy(['id', 'class', 'title', 'disabled']);
        $parser = new AttributeListParser($policy);
        $source = '{: #identity .one.two class="two three" title=\'A\\\'B\' disabled=true}tail';

        $parsed = $parser->parsePrefix($source);
        $closing = strpos($source, '}');

        self::assertNotNull($parsed);
        self::assertIsInt($closing);
        self::assertSame($closing + 1, $parsed->length);
        self::assertSame([
            'class' => 'one two three',
            'id' => 'identity',
            'title' => "A'B",
            'disabled' => true,
        ], $parsed->attributes);
        self::assertSame(['id', 'class', 'title', 'disabled'], $policy->allowed());
        self::assertNull($parser->parseWhole($source));
    }

    /**
     * @return iterable<string, array{string, AttributesPolicy|null}>
     */
    public static function invalidLists(): iterable
    {
        $all = new AttributesPolicy(['id', 'class', 'name']);

        yield 'empty source' => ['', $all];
        yield 'empty list' => ['{}', $all];
        yield 'whitespace without close' => ['{   ', $all];
        yield 'empty shortcut' => ['{.}', $all];
        yield 'invalid name start' => ['{9=value}', $all];
        yield 'missing equals' => ['{name}', $all];
        yield 'missing value' => ['{name=}', $all];
        yield 'missing value and close' => ['{name=', $all];
        yield 'invalid id' => ['{id=9bad}', $all];
        yield 'invalid class' => ['{class=bad.dot}', $all];
        yield 'control in plain value' => ["{name=bad\x7F}", $all];
        yield 'control in quoted value' => ["{name=\"bad\x7F\"}", $all];
        yield 'unclosed quoted value' => ['{name="bad}', $all];
        yield 'long shortcut' => ['{.'.str_repeat('a', 65).'}', $all];
        yield 'long name' => ['{'.str_repeat('a', 65).'=x}', $all];
        yield 'list byte limit' => [
            '{name=x'.str_repeat(' ', 40).'}',
            new AttributesPolicy(['name'], maxListBytes: 32, maxValueBytes: 32),
        ];
    }

    #[DataProvider('invalidLists')]
    public function testParserRejectsMalformedOrOverBudgetLists(string $source, ?AttributesPolicy $policy): void
    {
        self::assertNull((new AttributeListParser($policy ?? new AttributesPolicy()))->parseWhole($source));
    }

    public function testInjectorHandlesNestedVoidMalformedAndExistingTags(): void
    {
        $injector = new AttributesHtmlInjector();
        $escape = static fn (string $name, string $value): ?string => 'drop' === $name
            ? null
            : htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5);

        self::assertSame('plain', $injector->first('plain', ['class' => 'x'], $escape));
        self::assertSame('<div title="open', $injector->first('<div title="open', ['class' => 'x'], $escape));
        self::assertSame(
            " \n<div class=\"one two\" title='old' hidden>Text</div>",
            $injector->first(
                " \n<div class='one' title='old'>Text</div>",
                ['class' => 'one two', 'title' => 'new', 'hidden' => true, 'drop' => 'x'],
                $escape,
            ),
        );
        self::assertSame('<i class="x">one<i>two</i></i>', $injector->last(
            '<i>one<i>two</i></i>',
            ['class' => 'x'],
            $escape,
        ));
        self::assertSame('<em class="x"><span>text</span></em>', $injector->last(
            '<em><span>text</span></em>',
            ['class' => 'x'],
            $escape,
        ));
        self::assertSame('<widget    class="x">text</widget>', $injector->last(
            '<widget   >text</widget>',
            ['class' => 'x'],
            $escape,
        ));
        self::assertSame('<img class="media" />'." \n", $injector->last(
            '<img />'." \n",
            ['class' => 'media'],
            $escape,
        ));
        self::assertSame('<b class="x">text</b>', $injector->last(
            '<b class=x>text</b>',
            ['class' => 'x'],
            $escape,
        ));
        self::assertSame('<b class="x">text</b>', $injector->last(
            '<b class>text</b>',
            ['class' => 'x'],
            $escape,
        ));
        self::assertSame('plain', $injector->last('plain', [], $escape));
        self::assertNull($injector->last('plain', ['class' => 'x'], $escape));
        self::assertNull($injector->last('<i>open', ['class' => 'x'], $escape));
        self::assertNull($injector->last('</i>', ['class' => 'x'], $escape));
        self::assertSame(
            '<! bad <i class="x">open</i>',
            $injector->last('<! bad <i>open</i>', ['class' => 'x'], $escape),
        );
        self::assertNull($injector->last('<b title="open <i>text</i>', ['class' => 'x'], $escape));
        self::assertSame(
            '<i>text</i>',
            $injector->first(
                '<i>text</i>',
                ['class' => 'drop'],
                static fn (string $name, string $value): ?string => null,
            ),
        );
        self::assertSame(['class' => '', 'id' => 'kept'], AttributeSet::merge(
            ['class' => true],
            ['id' => 'kept'],
        ));
    }

    public function testInlineOutputKeepsMalformedListsAndSupportsDirectFallbackMethods(): void
    {
        $parser = new AttributeListParser(new AttributesPolicy());
        $output = new AttributesInlineOutput($parser);
        $node = new InlineNode('');
        $html = new HtmlInlineOutputContext($node, null, '{.}', HtmlPolicy::safe());
        $markdown = new MarkdownInlineOutputContext($node, null, '{.}', new MarkdownStyle());

        self::assertSame('{.}', $output->render($html));
        self::assertSame('<em>x</em>{.}', $output->accumulate($html, '<em>x</em>'));
        self::assertSame('{.}', $output->print($markdown));
    }

    public function testPlannedDecoratorIsConservativeWithoutProjectionOrOrdinal(): void
    {
        $decorator = new AttributesBlockDecorator();
        $context = new HtmlNodeOutputContext(
            'paragraph',
            new SourceRange(0, 4),
            'Text',
            HtmlPolicy::safe(),
        );

        self::assertSame('<p>Text</p>', $decorator->decorate($context, '<p>Text</p>'));
        self::assertSame(
            '<p>Text</p>',
            $decorator->decorateWithPlan($context, '<p>Text</p>', new DocumentRenderPlan()),
        );

        $plan = new DocumentRenderPlan();
        $plan->provide(new AttributesCatalog());
        self::assertSame(
            '<p>Text</p>',
            $decorator->decorateWithPlan($context, '<p>Text</p>', $plan),
        );
    }

    public function testUntargetableBlocksAndEndOfFileListsAreIgnored(): void
    {
        $factory = Markdown::github()->with(new \Alto\Markdown\Extension\Attributes\AttributesExtension());

        self::assertSame(
            "&lt;div&gt;\n",
            $factory->toHtml("{.ignored}\n<div>\n"),
        );
        self::assertSame('', $factory->toHtml("{.orphan}\n"));
        self::assertSame(
            "<pre><code>{.indented}\n</code></pre>\n",
            $factory->toHtml("    {.indented}\n"),
        );
    }

    public function testBlockUrlAttributesUseTheHtmlPolicy(): void
    {
        $factory = Markdown::commonmark()->with(
            new \Alto\Markdown\Extension\Attributes\AttributesExtension(
                new AttributesPolicy(['href']),
            ),
        );
        $source = "{href=javascript:alert(1)}\n# Heading\n";

        self::assertSame("<h1>Heading</h1>\n", $factory->toHtml($source));
        self::assertSame(
            '<h1 href="javascript:alert(1)">Heading</h1>'."\n",
            $factory->toHtml(
                $source,
                renderOptions: new \Alto\Markdown\Render\RenderOptions(htmlPolicy: HtmlPolicy::spec()),
            ),
        );
    }
}
