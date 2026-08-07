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
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Render\MarkdownRenderer;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

/**
 * Deep-container robustness (PL.5).
 *
 * The block HTML walk and the Markdown block printer are iterative, so a
 * 20,000-deep container renders without exhausting the native C stack (the
 * old recursive walks crashed with SIGSEGV around depth 6,000 to 20,000).
 * Each case also guards peak memory: the explicit stacks stay proportional to
 * output size, so a quadratic regression (holding every intermediate string)
 * would trip the bound.
 *
 * Depths past ParseOptions::DEFAULT_MAX_NESTING_DEPTH require the unbounded
 * option: the default refuses hostile nesting at parse time. These cases are
 * what makes that opt-out safe to offer.
 */
final class DeepContainerRobustnessTest extends TestCase
{
    private const int DEPTH = 20000;

    private static function unbounded(): ParseOptions
    {
        return (new ParseOptions())->withUnboundedNestingDepth();
    }

    /**
     * Peak allocation the render itself is allowed to add on top of the
     * process baseline. The iterative walks stay proportional to output size
     * (well under 32 MiB for a 20,000-deep document); the recursive memo
     * approach they replace would need hundreds of MiB.
     */
    private const int RENDER_MEMORY_BUDGET = 134217728; // 128 MiB

    public function testDeepNestedBlockquotesRenderToHtml(): void
    {
        $markdown = str_repeat('> ', self::DEPTH)."text\n";

        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();
        $html = Markdown::gfm()->fromString($markdown, self::unbounded())->toHtml();
        $renderPeak = memory_get_peak_usage(true) - $baseline;

        self::assertSame(str_repeat("<blockquote>\n", self::DEPTH).'<p>text</p>'."\n".str_repeat("</blockquote>\n", self::DEPTH), $html);
        self::assertLessThan(self::RENDER_MEMORY_BUDGET, $renderPeak);
    }

    public function testDeepNestedBlockquotesMatchAcrossLanes(): void
    {
        $markdown = str_repeat('> ', self::DEPTH)."text\n";

        $direct = Markdown::gfm()->toHtml($markdown, self::unbounded());
        $document = Markdown::gfm()->fromString($markdown, self::unbounded())->toHtml();

        self::assertSame($document, $direct);
    }

    public function testDeepNestedBlockquotesRenderToMarkdown(): void
    {
        $markdown = str_repeat('> ', self::DEPTH)."text\n";

        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();
        $rendered = Markdown::gfm()
            ->fromString($markdown, self::unbounded())
            ->toMarkdown(new RenderOptions());
        $renderPeak = memory_get_peak_usage(true) - $baseline;

        self::assertStringStartsWith(str_repeat('> ', 3), $rendered);
        self::assertStringEndsWith("text\n", $rendered);
        // A depth-N blockquote prints as "> " repeated N times before "text".
        self::assertSame(str_repeat('> ', self::DEPTH).'text', trim($rendered));
        self::assertLessThan(self::RENDER_MEMORY_BUDGET, $renderPeak);
    }

    public function testDeepNestedListsRenderToMarkdownIteratively(): void
    {
        $depth = MarkdownRenderer::STACK_LIMIT + 20;
        $markdown = str_repeat('- ', $depth)."text\n";

        $rendered = Markdown::gfm()
            ->fromString($markdown, self::unbounded())
            ->toMarkdown(new RenderOptions());

        self::assertSame($markdown, $rendered);
    }

    public function testTapeWalkRendersDeepEmphasis(): void
    {
        // `**foo **bar****` forces the GFM nested-strong fallback onto the
        // recursive tape walk (HtmlInlineRenderer::renderTapeChildren); the
        // trailing deep emphasis then renders through that walk. A moderate
        // depth keeps the quadratic inline parser fast while still driving the
        // walk far past any shallow nesting.
        $depth = 2000;
        $markdown = '**foo **bar**** '.str_repeat('*a ', $depth).'x'.str_repeat(' a*', $depth)."\n";

        $html = Markdown::gfm()->toHtml($markdown, self::unbounded());

        self::assertSame($depth, substr_count($html, '<em>'));
        self::assertSame($depth, substr_count($html, '</em>'));
        self::assertStringContainsString('<strong>foo bar</strong>', $html);
    }
}
