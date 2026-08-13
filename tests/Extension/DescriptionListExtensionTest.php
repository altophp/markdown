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

use Alto\Markdown\Extension\DescriptionList\DescriptionListExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

final class DescriptionListExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testDescriptionListRendersThroughDirectAndDocumentLanes(): void
    {
        $factory = Markdown::commonmark()->with(new DescriptionListExtension());
        $source = "Term\n: Definition\n";
        $expected = "<dl>\n<dt>Term</dt>\n<dd>Definition</dd>\n</dl>\n";

        self::assertSame($expected, $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame($source, $document->toMarkdown(new RenderOptions()));
    }

    public function testMultipleTermsAndDescriptionsShareOneList(): void
    {
        $factory = Markdown::commonmark()->with(new DescriptionListExtension());
        $source = "**First**\nSecond\n: One\n: Two with [link](/target)\n\nThird\n: Three\n";

        self::assertSame(
            "<dl>\n"
            . "<dt><strong>First</strong></dt>\n"
            . "<dt>Second</dt>\n"
            . "<dd>One</dd>\n"
            . "<dd>Two with <a href=\"/target\">link</a></dd>\n"
            . "<dt>Third</dt>\n"
            . "<dd>Three</dd>\n"
            . "</dl>\n",
            $factory->toHtml($source),
        );

        self::assertSame('/target', $factory->fromString($source)->links()->first()?->destination());
    }

    public function testDescriptionCanContainNestedBlocksAndBecomeLoose(): void
    {
        $factory = Markdown::github()->with(new DescriptionListExtension());
        $source = "Term\n: First paragraph\n\n  - one\n  - two\n";

        self::assertSame(
            "<dl>\n<dt>Term</dt>\n<dd>\n"
            . "<p>First paragraph</p>\n"
            . "<ul>\n<li>one</li>\n<li>two</li>\n</ul>\n"
            . "</dd>\n</dl>\n",
            $factory->toHtml($source),
        );
    }

    public function testEmptyAndMultilineDescriptionsNormalizeConservatively(): void
    {
        $factory = Markdown::commonmark()->with(new DescriptionListExtension());

        self::assertSame(
            "<dl>\n<dt>Empty</dt>\n<dd></dd>\n</dl>\n<p>After</p>\n",
            $factory->toHtml("Empty\n: \n\nAfter\n"),
        );
        self::assertSame(
            "Empty\n:\n",
            $factory->fromString("Empty\n: \n")->toMarkdown(new RenderOptions()),
        );

        $document = $factory->fromString("Term\n: First\n  second\n");
        self::assertSame(
            "<dl>\n<dt>Term</dt>\n<dd>First\nsecond</dd>\n</dl>\n",
            $document->toHtml(),
        );
        self::assertSame("Term\n: First\n  second\n", $document->toMarkdown(new RenderOptions()));
    }

    public function testAdjacentGroupsMergeAfterAnEarlierRootBlock(): void
    {
        $factory = Markdown::commonmark()->with(new DescriptionListExtension());
        $source = "# Intro\n\nFirst\n: One\n\nSecond\n: Two\n";

        self::assertSame(
            "<h1>Intro</h1>\n<dl>\n<dt>First</dt>\n<dd>One</dd>\n"
            . "<dt>Second</dt>\n<dd>Two</dd>\n</dl>\n",
            $factory->toHtml($source),
        );
    }

    public function testDeepDescriptionListUsesTheHeapBoundRenderer(): void
    {
        $factory = Markdown::commonmark()->with(new DescriptionListExtension());
        $prefix = str_repeat('> ', 501);
        $html = $factory->toHtml(
            $prefix . "Term\n" . $prefix . ": Definition\n",
            new ParseOptions(maxNestingDepth: 0),
        );

        self::assertSame(501, substr_count($html, '<blockquote>'));
        self::assertStringContainsString(
            "<dl>\n<dt>Term</dt>\n<dd>Definition</dd>\n</dl>\n",
            $html,
        );
    }

    public function testInvalidMarkersRemainOrdinaryMarkdown(): void
    {
        $factory = Markdown::commonmark()->with(new DescriptionListExtension());

        self::assertSame(
            "<p>Term\n:no-space\n: too-indented</p>\n",
            $factory->toHtml("Term\n:no-space\n    : too-indented\n"),
        );
        self::assertSame("<p>: no term</p>\n", $factory->toHtml(": no term\n"));
    }

    public function testInactiveProfileKeepsTheSyntaxAndCostUnchanged(): void
    {
        $source = "Term\n: Definition\n";

        Instrumentation::reset();
        self::assertSame("<p>Term\n: Definition</p>\n", Markdown::commonmark()->toHtml($source));
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);

        $plain = Markdown::commonmark()->with(new DescriptionListExtension());
        self::assertSame("<p>Plain paragraph.</p>\n", $plain->toHtml("Plain paragraph.\n"));

        self::assertSame([], (new DescriptionListExtension())->blockConstructs());
    }
}
