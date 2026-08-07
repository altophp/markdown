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

use Alto\Markdown\Exception\RenderException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\FusedInlineRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Fused-lane behavior that the byte-parity gate cannot see: the direct
 * lane renders rich inlines without building inline tapes, keeps the
 * emphasis search-work linear, and falls back to the tape path only for
 * GFM nested strong emphasis.
 */
final class FusedInlineRendererTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testDirectConversionSkipsInlineTapeConstruction(): void
    {
        $factory = Markdown::github();
        Instrumentation::reset();

        $html = $factory->toHtml("A **strong** [link](/url) and `code`.\n");

        self::assertSame("<p>A <strong>strong</strong> <a href=\"/url\">link</a> and <code>code</code>.</p>\n", $html);
        self::assertSame(0, Instrumentation::$inlineParses);
        self::assertSame(1, Instrumentation::$fusedInlineRenders);
        self::assertSame(0, Instrumentation::$fusedInlineFallbacks);
    }

    public function testUnknownEmissionKindFailsExplicitly(): void
    {
        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('No fused HTML emission for inline kind 999.');

        new FusedInlineRenderer()->emit(999, 0);
    }

    public function testNestedStrongFallsBackToTapePath(): void
    {
        $factory = Markdown::gfm();
        Instrumentation::reset();

        $html = $factory->toHtml("**foo **bar****\n");

        self::assertSame("<p><strong>foo bar</strong></p>\n", $html);
        self::assertSame(1, Instrumentation::$fusedInlineFallbacks);
        self::assertSame(['nested-strong' => 1], Instrumentation::$fusedFallbackReasons);
        self::assertSame(1, Instrumentation::$inlineParses);
    }

    public function testCommonMarkNestedStrongStaysFused(): void
    {
        $factory = Markdown::commonmark();
        Instrumentation::reset();

        $html = $factory->toHtml("**foo **bar****\n");

        self::assertSame("<p><strong>foo <strong>bar</strong></strong></p>\n", $html);
        self::assertSame(0, Instrumentation::$fusedInlineFallbacks);
    }

    public function testFailedImageBracketDoesNotLeakIntoNextImageAlt(): void
    {
        $factory = Markdown::commonmark();
        Instrumentation::reset();

        // The first "![" never forms an image: its bracket pops at "]"
        // without a match. The alt run collected while it was open must
        // not leak into the later image's alt attribute.
        $html = $factory->toHtml("![stale text] and ![a](b)\n");

        self::assertSame("<p>![stale text] and <img src=\"b\" alt=\"a\" /></p>\n", $html);
        self::assertSame(0, Instrumentation::$fusedInlineFallbacks);
    }

    public function testImageAltTextFlattensEveryInlineEmission(): void
    {
        $source = "![text `code` <https://example.com> <em>\nsoft  \nhard](image.png)\n";

        $html = Markdown::commonmark()->toHtml($source);

        self::assertSame(
            "<p><img src=\"image.png\" alt=\"text code https://example.com &lt;em&gt;\nsoft\nhard\" /></p>\n",
            $html,
        );
        self::assertSame(0, Instrumentation::$fusedInlineFallbacks);
    }

    public function testTimedRichTableCellFallbackBalancesInstrumentation(): void
    {
        Instrumentation::measure();

        try {
            $html = Markdown::gfm()->toHtml("| Value |\n| --- |\n| **foo **bar**** |\n");
        } finally {
            Instrumentation::disable();
        }

        self::assertStringContainsString('<td><strong>foo bar</strong></td>', $html);
        self::assertSame(['nested-strong' => 1], Instrumentation::$fusedFallbackReasons);
        self::assertGreaterThan(0, Instrumentation::$stageEnters['inline-build'] ?? 0);
        self::assertSame(0, Instrumentation::regionDepth());
    }

    public function testUnmatchedClosersHaveLinearSearchWork(): void
    {
        $factory = Markdown::commonmark();
        $previousSteps = 0;

        foreach ([1000, 2000, 4000, 8000] as $size) {
            Instrumentation::reset();
            Instrumentation::$trackInlineComplexity = true;
            $factory->toHtml(str_repeat('a* ', $size)."\n");
            $steps = Instrumentation::$delimiterSearchSteps;

            self::assertLessThanOrEqual($size, $steps);

            if ($previousSteps > 0) {
                self::assertLessThanOrEqual($previousSteps * 2 + 2, $steps);
            }

            $previousSteps = $steps;
        }
    }

    public function testIndependentMatchesHaveLinearSearchWork(): void
    {
        $factory = Markdown::commonmark();
        $size = 4000;
        Instrumentation::reset();
        Instrumentation::$trackInlineComplexity = true;
        $factory->toHtml(str_repeat('*x* ', $size)."\n");

        self::assertLessThanOrEqual($size, Instrumentation::$delimiterSearchSteps);
    }
}
