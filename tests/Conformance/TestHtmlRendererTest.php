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

namespace Alto\Markdown\Tests\Conformance;

use PHPUnit\Framework\TestCase;

final class TestHtmlRendererTest extends TestCase
{
    public function testRendersParagraphs(): void
    {
        $html = new TestHtmlRenderer()->render("aaa\nbbb\n\nccc\n");

        self::assertSame("<p>aaa\nbbb</p>\n<p>ccc</p>\n", $html);
    }

    public function testEscapesRawTextAndKeepsHardBreaks(): void
    {
        $html = new TestHtmlRenderer()->render("a < b & \"c\"  \nnext\n");

        self::assertSame("<p>a &lt; b &amp; &quot;c&quot;<br />\nnext</p>\n", $html);
    }

    public function testEmptyInputRendersNothing(): void
    {
        self::assertSame('', new TestHtmlRenderer()->render(''));
    }

    public function testType7HtmlDoesNotInterruptLazyContinuation(): void
    {
        // <b> is a type-7 HTML block, which cannot interrupt a paragraph even
        // as a lazy continuation, so it stays in the list item's paragraph.
        $html = new TestHtmlRenderer()->render("- m\n<b>");

        self::assertSame("<ul>\n<li>m\n<b></li>\n</ul>\n", $html);
    }

    public function testType6HtmlInterruptsLazyContinuation(): void
    {
        // <div> is a type-6 HTML block, which does interrupt: the list closes.
        $html = new TestHtmlRenderer()->render("- m\n<div>");

        self::assertSame("<ul>\n<li>m</li>\n</ul>\n<div>\n", $html);
    }

    public function testSetextHeadingHonorsHardBreak(): void
    {
        // Two trailing spaces before the line ending of a multi-line setext
        // heading are a hard break (spec 6.7), rendered as <br />.
        $html = new TestHtmlRenderer()->render("foo  \nbar\n===");

        self::assertSame("<h1>foo<br />\nbar</h1>\n", $html);
    }

    public function testCodeSpanStripsContinuationLineIndent(): void
    {
        // Paragraph continuation lines have leading whitespace removed before
        // inline parsing (spec 4.8), so a code span crossing the line ending
        // sees "b\nc" and renders one space, not the raw indentation.
        $html = new TestHtmlRenderer()->render("a `b\n   c` d");

        self::assertSame("<p>a <code>b c</code> d</p>\n", $html);
    }

    public function testCodeSpanStripsIndentedLazyContinuationInBlockQuote(): void
    {
        // A 4-space-indented lazy continuation line inside a block quote is
        // still paragraph content: its leading whitespace is stripped, so the
        // code span crossing the line ending sees "b\nc".
        $html = new TestHtmlRenderer()->render("> a `b\n    c` d");

        self::assertSame("<blockquote>\n<p>a <code>b c</code> d</p>\n</blockquote>\n", $html);
    }

    public function testMultilineLeftoverAfterReferenceDefinitionRenders(): void
    {
        // The definition consumes the first line; the leftover paragraph must
        // keep its per-line pairs so the inline scanner sees two segments, not
        // one segment holding a raw newline.
        $html = new TestHtmlRenderer()->render("[a]: /url\nc\nd");

        self::assertSame("<p>c\nd</p>\n", $html);
    }

    public function testAutolinkInsideLinkTextRendersAsPlainText(): void
    {
        $html = new TestHtmlRenderer()->render('[<a@m>]()');

        self::assertSame("<p><a href=\"\">a@m</a></p>\n", $html);
    }

    public function testZeroOrderedListStartsAfterReferenceDefinitionOnlyParagraph(): void
    {
        $html = new TestHtmlRenderer()->render("[f]:l\n0.");

        self::assertSame("<ol start=\"0\">\n<li></li>\n</ol>\n", $html);
    }

    public function testImageAltTextIncludesRawInlineHtmlText(): void
    {
        $html = new TestHtmlRenderer()->render('![<m>]()');

        self::assertSame("<p><img src=\"\" alt=\"&lt;m&gt;\" /></p>\n", $html);
    }

    public function testReferenceDefinitionDoesNotForceTightItemBlockStyle(): void
    {
        $html = new TestHtmlRenderer()->render("- [a]:b\nb");

        self::assertSame("<ul>\n<li>b</li>\n</ul>\n", $html);
    }

    public function testIndentedDifferentMarkerStartsChildListOfEmptyItem(): void
    {
        $html = new TestHtmlRenderer()->render("*\n  -");

        self::assertSame("<ul>\n<li>\n<ul>\n<li></li>\n</ul>\n</li>\n</ul>\n", $html);
    }

    public function testIndentedCodeInsideListUsesAbsoluteTabColumn(): void
    {
        $html = new TestHtmlRenderer()->render("* \t  d");

        self::assertSame("<ul>\n<li>\n<pre><code>d\n</code></pre>\n</li>\n</ul>\n", $html);
    }

    public function testFencedCodeContentDoesNotStripTabsAsSpaces(): void
    {
        $html = new TestHtmlRenderer()->render(" ```\n\t");

        self::assertSame("<pre><code>\t\n</code></pre>\n", $html);
    }

    public function testPartiallyConsumedTabBeforeBlockQuoteDoesNotForceCode(): void
    {
        $html = new TestHtmlRenderer()->render("+\n\t>   (");

        self::assertSame("<ul>\n<li>\n<blockquote>\n<p>(</p>\n</blockquote>\n</li>\n</ul>\n", $html);
    }

    public function testHtmlBlockPreservesVirtualIndentInsideList(): void
    {
        $html = new TestHtmlRenderer()->render("1.\n\t<!--");

        self::assertSame("<ol>\n<li>\n <!--\n</li>\n</ol>\n", $html);
    }

    public function testNestedListCodeConsumesMarkerIndentPad(): void
    {
        $html = new TestHtmlRenderer()->render("*\n\t*     b");

        self::assertSame("<ul>\n<li>\n<ul>\n<li>\n<pre><code>b\n</code></pre>\n</li>\n</ul>\n</li>\n</ul>\n", $html);
    }
}
