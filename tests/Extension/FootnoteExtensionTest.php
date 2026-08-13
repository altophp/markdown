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

use Alto\Markdown\Extension\Footnote\FootnoteExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

final class FootnoteExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testFootnotesRenderThroughDirectAndDocumentLanes(): void
    {
        $factory = Markdown::commonmark()->with(new FootnoteExtension());
        $source = "Text[^note] and again[^note].\n\n[^note]: Footnote with **strong**.\n";
        $expected = '<p>Text<sup id="fnref-1"><a href="#fn-1" role="doc-noteref">1</a></sup>'
            . " and again<sup id=\"fnref-1-2\"><a href=\"#fn-1\" role=\"doc-noteref\">1</a></sup>.</p>\n"
            . "<div class=\"footnotes\" role=\"doc-endnotes\">\n<hr />\n<ol>\n"
            . "<li id=\"fn-1\" role=\"doc-endnote\">\n"
            . '<p>Footnote with <strong>strong</strong>. '
            . '<a href="#fnref-1" role="doc-backlink">↩</a> '
            . "<a href=\"#fnref-1-2\" role=\"doc-backlink\">↩</a></p>\n"
            . "</li>\n</ol>\n</div>\n";

        self::assertSame($expected, $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame(
            "Text[^note] and again[^note]\\.\n\n[^note]: Footnote with **strong**.\n",
            $document->toMarkdown(new RenderOptions()),
        );
    }

    public function testReferenceOrderControlsNumbersAndDefinitionsMayComeFirst(): void
    {
        $factory = Markdown::commonmark()->with(new FootnoteExtension());
        $source = "[^beta]: B\n\n[^alpha]: A\n\nMissing[^none], alpha[^alpha], beta[^beta].\n";
        $html = $factory->toHtml($source);

        self::assertStringContainsString(
            'Missing[^none], alpha<sup id="fnref-1"><a href="#fn-1" role="doc-noteref">1</a></sup>, '
            . 'beta<sup id="fnref-2"><a href="#fn-2" role="doc-noteref">2</a></sup>.',
            $html,
        );
        self::assertStringContainsString('<li id="fn-1" role="doc-endnote">', $html);
        self::assertStringContainsString('<p>A ', $html);
        self::assertStringContainsString('<li id="fn-2" role="doc-endnote">', $html);
        self::assertStringContainsString('<p>B ', $html);
    }

    public function testDefinitionsSupportIndentedNestedBlocks(): void
    {
        $factory = Markdown::github()->with(new FootnoteExtension());
        $source = "See[^list].\n\n[^list]: First\n\n    - one\n    - two\n";

        self::assertStringContainsString(
            "<li id=\"fn-1\" role=\"doc-endnote\">\n"
            . "<p>First</p>\n"
            . "<ul>\n<li>one</li>\n<li>two</li>\n</ul> "
            . "<a href=\"#fnref-1\" role=\"doc-backlink\">↩</a>\n"
            . '</li>',
            $factory->toHtml($source),
        );
    }

    public function testMissingUnusedInvalidAndEscapedFootnotesStaySafe(): void
    {
        $factory = Markdown::commonmark()->with(new FootnoteExtension());
        $source = "Missing[^none], escaped \\[^note], `[^code]`, and unclosed[^open.\n\n"
            . "[^unused]: Hidden\n"
            . "[^bad label]: Ordinary\n";

        self::assertSame(
            "<p>Missing[^none], escaped [^note], <code>[^code]</code>, and unclosed[^open.</p>\n",
            $factory->toHtml($source),
        );
        self::assertSame('[^inline]', $factory->toInlineHtml('[^inline]'));
    }

    public function testDefinitionsDoNotInterruptParagraphs(): void
    {
        $factory = Markdown::commonmark()->with(new FootnoteExtension());

        self::assertSame(
            "<p>Paragraph\n[^note]: Ordinary text</p>\n",
            $factory->toHtml("Paragraph\n[^note]: Ordinary text\n"),
        );
    }

    public function testFootnoteAnchorsSurviveTheCuratedPolicy(): void
    {
        $factory = Markdown::commonmark()->with(new FootnoteExtension());
        $html = $factory->toHtml(
            "See[^safe].\n\n[^safe]: Safe.\n",
            renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
        );

        self::assertStringContainsString('role="doc-noteref"', $html);
        self::assertStringContainsString('role="doc-endnotes"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testReferenceInsideALinkDoesNotCreateANestedAnchor(): void
    {
        $factory = Markdown::commonmark()->with(new FootnoteExtension());

        self::assertSame(
            "<p><a href=\"/target\">See [^note]</a>.</p>\n",
            $factory->toHtml("[See [^note]](/target).\n\n[^note]: Hidden\n"),
        );
    }

    public function testPartialNodeRenderingKeepsAnUnmaterializedReferenceLiteral(): void
    {
        $document = Markdown::commonmark()
            ->with(new FootnoteExtension())
            ->fromString("See[^note].\n\n[^note]: Body\n");
        $paragraph = $document->query()->kind('paragraph')->get()->first();

        self::assertNotNull($paragraph);
        self::assertSame(
            "<p>See[^note].</p>\n",
            new HtmlDocumentRenderer()->renderNode($document->model(), $paragraph),
        );
    }

    public function testInactiveProfileDoesNotScanFootnoteCandidates(): void
    {
        $extension = new FootnoteExtension();
        self::assertSame([], $extension->nativeHtmlInlineRenderers());
        self::assertSame([], $extension->nativeMarkdownInlinePrinters());

        Instrumentation::reset();
        self::assertSame("<p>Plain text.</p>\n", Markdown::commonmark()->toHtml("Plain text.\n"));
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);

        $factory = Markdown::commonmark()->with($extension);
        Instrumentation::reset();
        self::assertSame("<p>Plain text.</p>\n", $factory->toHtml("Plain text.\n"));
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);
    }
}
