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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Inline\InlineSourceView;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Render\HtmlInlineRenderer;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\PlainInlineText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plain-inline fast path is shared by paragraph/heading rendering and
 * table-cell rendering (both call PlainInlineText::render() with the
 * document's compiled profile). All other fast-path tests exercise it only
 * through Markdown::gfm(); this file proves output-identity under the
 * strict Markdown::commonmark() profile too.
 *
 * The commonmark profile has no table extension (tables are GFM-only), so
 * a "plain table cell" cannot be produced end-to-end through table markdown
 * here. Instead, testPlainTableCellFastPathMatchesFullParse() exercises the
 * same cellHtml() call path directly, the way HtmlDocumentRenderer does.
 */
final class HtmlPlainFastPathCommonmarkTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function docs(): iterable
    {
        yield 'plain paragraph' => ["hello world\n", "<p>hello world</p>\n"];
        yield 'plain heading' => ["# Title here\n", "<h1>Title here</h1>\n"];
    }

    #[DataProvider('docs')]
    public function testPlainBlocksRenderIdentically(string $md, string $expected): void
    {
        self::assertSame($expected, Markdown::commonmark()->fromString($md)->toHtml());
    }

    public function testPlainTableCellFastPathMatchesFullParse(): void
    {
        $model = Markdown::commonmark()->fromString("x\n")->model();
        self::assertInstanceOf(ParsedDocumentModel::class, $model);
        $profile = $model->compiledProfile();

        $cell = 'a > b and "c"';
        $fast = PlainInlineText::render($cell, $profile);

        self::assertSame('a &gt; b and &quot;c&quot;', $fast);
        self::assertSame(new HtmlInlineRenderer()->renderMarkdown($model, $cell, HtmlPolicy::spec()), $fast);
    }

    public function testRichBlockStillFullyParsed(): void
    {
        // emphasis and link must both survive under commonmark (fast path must decline)
        $html = Markdown::commonmark()->fromString("a *b* and [x](http://e.co)\n")->toHtml();

        self::assertSame("<p>a <em>b</em> and <a href=\"http://e.co\">x</a></p>\n", $html);
    }

    public function testSourceFastPathHandlesEmptyAndOddLeadingWhitespacePairs(): void
    {
        $model = Markdown::commonmark()->fromString("x\n")->model();
        self::assertInstanceOf(ParsedDocumentModel::class, $model);
        $profile = $model->compiledProfile();

        self::assertSame(
            '',
            PlainInlineText::renderSource(new InlineSourceView(new SourceBuffer(''), []), $profile),
        );
        self::assertSame(
            "one\ntwo",
            PlainInlineText::renderSource(
                new InlineSourceView(new SourceBuffer("  one\n\t two"), [[0, 5, 0], [6, 11, 0]]),
                $profile,
            ),
        );
    }
}
