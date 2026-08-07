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

use Alto\Markdown\Markdown;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

final class HtmlRendererTest extends TestCase
{
    public function testDocumentRendersCommonInlineHtml(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nA **strong** [link](https://example.com \"site\") and `code`.\n");

        self::assertSame(
            "<h1>Title</h1>\n<p>A <strong>strong</strong> <a href=\"https://example.com\" title=\"site\">link</a> and <code>code</code>.</p>\n",
            $document->toHtml(),
        );
    }

    public function testCodeBlocksEscapeAndCarryLanguageClass(): void
    {
        $document = Markdown::github()->fromString("```php\n<?php echo \"<ok>\";\n```\n");

        self::assertSame(
            "<pre><code class=\"language-php\">&lt;?php echo &quot;&lt;ok&gt;&quot;;\n</code></pre>\n",
            $document->toHtml(),
        );
    }

    /**
     * CommonMark 0.31.2 example 34: the info string resolves character
     * references before the language word is escaped for the attribute.
     */
    public function testFenceInfoStringResolvesCharacterReferences(): void
    {
        $document = Markdown::commonmark()->fromString("``` f&ouml;&ouml;\nfoo\n```\n");

        self::assertSame(
            "<pre><code class=\"language-föö\">foo\n</code></pre>\n",
            $document->toHtml(),
        );
    }

    /**
     * CommonMark 0.31.2 example 24 stays passing: backslash escapes still
     * resolve, and a numeric reference decodes like a named one.
     */
    public function testFenceInfoStringResolvesEscapesAndNumericReferences(): void
    {
        self::assertSame(
            "<pre><code class=\"language-foo+bar\">foo\n</code></pre>\n",
            Markdown::commonmark()->fromString("``` foo\\+bar\nfoo\n```\n")->toHtml(),
        );

        self::assertSame(
            "<pre><code class=\"language-f#x\">foo\n</code></pre>\n",
            Markdown::commonmark()->fromString("``` f&#35;&#x78;\nfoo\n```\n")->toHtml(),
        );
    }

    /**
     * Decoding runs before escaping, never after: a decoded "&" or quote
     * must still reach the attribute in escaped form.
     */
    public function testFenceInfoStringEscapesAfterDecoding(): void
    {
        self::assertSame(
            "<pre><code class=\"language-a&amp;b&quot;c&lt;d\">foo\n</code></pre>\n",
            Markdown::commonmark()->fromString("``` a&amp;b&quot;c&lt;d\nfoo\n```\n")->toHtml(),
        );
    }

    /**
     * An unresolvable reference stays literal and is escaped as written.
     */
    public function testFenceInfoStringKeepsUnknownReferencesLiteral(): void
    {
        self::assertSame(
            "<pre><code class=\"language-&amp;nope;\">foo\n</code></pre>\n",
            Markdown::commonmark()->fromString("``` &nope;\nfoo\n```\n")->toHtml(),
        );
    }

    public function testGfmTablesRenderToHtmlTable(): void
    {
        $document = Markdown::gfm()->fromString("| A | B |\n| :--- | ---: |\n| `x` | y |\n");

        self::assertSame(
            "<table>\n<thead>\n<tr>\n<th align=\"left\">A</th>\n<th align=\"right\">B</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td align=\"left\"><code>x</code></td>\n<td align=\"right\">y</td>\n</tr>\n</tbody>\n</table>\n",
            $document->toHtml(),
        );
    }

    public function testTaskListsRenderCheckboxes(): void
    {
        $document = Markdown::gfm()->fromString("- [ ] todo\n- [x] done\n");

        self::assertSame(
            "<ul>\n<li><input disabled=\"\" type=\"checkbox\"> todo</li>\n<li><input checked=\"\" disabled=\"\" type=\"checkbox\"> done</li>\n</ul>\n",
            $document->toHtml(),
        );
    }

    public function testGfmTagFilterAppliesToInlineHtml(): void
    {
        // The tagfilter is a spec-mode behaviour: raw HTML is passed
        // through (and only the nine dangerous tags neutralised). Under
        // the safe default, raw HTML is escaped instead.
        $document = Markdown::gfm()->fromString("<strong> <title>\n");

        self::assertSame(
            "<p><strong> &lt;title></p>\n",
            $document->toHtml(new RenderOptions(htmlPolicy: HtmlPolicy::spec())),
        );
    }

    /**
     * CommonMark 0.31.2 example 174: an HTML block inside a container drops
     * the container prefix on every continuation line, not just the first.
     */
    public function testHtmlBlockInsideBlockQuoteDropsTheContainerPrefix(): void
    {
        $spec = new RenderOptions(htmlPolicy: HtmlPolicy::spec());

        self::assertSame(
            "<blockquote>\n<div>\nfoo\n</blockquote>\n<p>bar</p>\n",
            Markdown::commonmark()->fromString("> <div>\n> foo\n\nbar\n")->toHtml($spec),
        );

        self::assertSame(
            "<blockquote>\n<div>\nfoo\n</blockquote>\n<p>bar</p>\n",
            Markdown::gfm()->fromString("> <div>\n> foo\n\nbar\n")->toHtml($spec),
        );
    }

    /**
     * The prefix strip covers every container shape and nesting depth, and
     * leaves indentation that belongs to the markup itself alone.
     */
    public function testHtmlBlockPrefixStripCoversEveryContainerShape(): void
    {
        $spec = new RenderOptions(htmlPolicy: HtmlPolicy::spec());
        $cm = Markdown::commonmark();

        self::assertSame(
            "<blockquote>\n<pre>\na\n</pre>\n</blockquote>\n",
            $cm->fromString("> <pre>\n> a\n> </pre>\n")->toHtml($spec),
        );

        self::assertSame(
            "<blockquote>\n<blockquote>\n<div>\nfoo\n</blockquote>\n</blockquote>\n",
            $cm->fromString(">> <div>\n>> foo\n")->toHtml($spec),
        );

        self::assertSame(
            "<ul>\n<li>\n<div>\nfoo\n</li>\n</ul>\n",
            $cm->fromString("- <div>\n  foo\n")->toHtml($spec),
        );

        // Indentation past the container marker is markup, so it survives.
        self::assertSame(
            "<blockquote>\n<table>\n  <tr>\n    <td>x</td>\n</table>\n</blockquote>\n",
            $cm->fromString("> <table>\n>   <tr>\n>     <td>x</td>\n> </table>\n")->toHtml($spec),
        );
    }

    /**
     * A root-level HTML block stays an opaque leaf read as one contiguous
     * span (RP.8); the container path must not change it.
     */
    public function testRootHtmlBlockKeepsItsContiguousSpan(): void
    {
        $spec = new RenderOptions(htmlPolicy: HtmlPolicy::spec());

        self::assertSame(
            "<div>\n  foo\n</div>\n<p>bar</p>\n",
            Markdown::commonmark()->fromString("<div>\n  foo\n</div>\n\nbar\n")->toHtml($spec),
        );
    }

    /**
     * The raw-HTML policy still governs a container-nested block: the source
     * handed to it is the stripped one, so the escape is of clean markup.
     */
    public function testNestedHtmlBlockHonoursTheRawHtmlPolicy(): void
    {
        self::assertSame(
            "<blockquote>\n&lt;div&gt;\nfoo\n</blockquote>\n",
            Markdown::commonmark()->fromString("> <div>\n> foo\n")->toHtml(),
        );
    }

    public function testRawInlineHtmlIsEscapedByDefault(): void
    {
        $document = Markdown::gfm()->fromString("<strong> <title>\n");

        self::assertSame(
            "<p>&lt;strong&gt; &lt;title&gt;</p>\n",
            $document->toHtml(),
        );
    }

    public function testAutolinksCarryTheUrlAsVisibleText(): void
    {
        $document = Markdown::github()->fromString("Visit https://example.com now and <https://alt.example>.\n");

        self::assertSame(
            "<p>Visit <a href=\"https://example.com\">https://example.com</a> now and <a href=\"https://alt.example\">https://alt.example</a>.</p>\n",
            $document->toHtml(),
        );
    }

    public function testEmailAutolinkKeepsMailtoHrefButPlainText(): void
    {
        $document = Markdown::github()->fromString("<foo@bar.com>\n");

        self::assertSame(
            "<p><a href=\"mailto:foo@bar.com\">foo@bar.com</a></p>\n",
            $document->toHtml(),
        );
    }

    public function testGitHubAlertsRenderAsAlertDivs(): void
    {
        $document = Markdown::github()->fromString("> [!WARNING]\n> Be **careful** here.\n");

        self::assertSame(
            "<div class=\"markdown-alert markdown-alert-warning\">\n<p class=\"markdown-alert-title\">Warning</p>\n<p>Be <strong>careful</strong> here.</p>\n</div>\n",
            $document->toHtml(),
        );
    }

    public function testSectionRenderingIncludesHeadingAndBodyOnly(): void
    {
        $document = Markdown::github()->fromString("# One\n\nText.\n\n# Two\n\nOther.\n");
        $section = $document->section('One');

        self::assertSame(
            "<h1>One</h1>\n<p>Text.</p>\n",
            new HtmlDocumentRenderer()->renderSection($document->model(), $section),
        );
    }
}
