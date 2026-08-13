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

namespace Alto\Markdown\Tests\Performance;

use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Profile\CommonMarkProfile;
use Alto\Markdown\Profile\GfmProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

/**
 * The deterministic half of the performance gate.
 *
 * Wall-clock benchmarks belong in bench/, where a ratio measured in the same
 * run as the competitor survives machine drift. They cannot run here: on a
 * developer laptop a 1 KB document swings by more than 60 percent, so a
 * timing assertion would either flake or be too loose to catch anything.
 *
 * Counters do not swing. Every regression this project actually shipped was
 * visible as a count long before it was visible as a millisecond:
 *
 * - The GFM autolink extension declared 65 trigger bytes, including every
 *   letter and digit, which turned the literal-skipping scan into one
 *   iteration per byte and made the gfm profile 24x slower than commonmark on
 *   identical prose. A bound on the compiled byte set catches it instantly.
 * - The plain-text fast path required single-line paragraphs, so it never
 *   fired on real hard-wrapped content while firing on 100 percent of the
 *   synthetic corpus. A reach assertion catches that.
 * - Reference definition extraction copied and rescanned most of the
 *   document. A byte budget catches it.
 *
 * Assertions here are budgets with headroom, not exact values: they must fail
 * on a structural regression and never on a refactor.
 */
final class ComplexityBudgetTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::disable();
        Instrumentation::reset();
    }

    /**
     * The scan loop stops on every byte in this set. It must stay a small
     * set of genuine structural characters: anything that adds a whole class
     * of ordinary text bytes (letters, digits) silently converts the
     * strcspn-based literal skip into a per-character walk.
     */
    public function testCompiledScanBytesStaySmall(): void
    {
        $compiler = new ProfileCompiler();

        foreach (['commonmark' => new CommonMarkProfile(), 'gfm' => new GfmProfile()] as $name => $profile) {
            $compiled = $compiler->compile($profile);
            $special = $compiled->inlineSpecialBytes;

            self::assertLessThanOrEqual(16, \strlen($special), \sprintf(
                'The %s profile scans on %d special bytes ("%s"). A construct that declares whole ' .
                'character classes as triggers defeats the literal skip. Locate candidates with a ' .
                'substring search instead, the way ContentScannedInlineConstruct does.',
                $name,
                \strlen($special),
                addcslashes($special, "\0..\37"),
            ));

            self::assertSame('', preg_replace('/[^A-Za-z0-9]/', '', $special), \sprintf(
                'The %s profile treats alphanumeric bytes as scan triggers.',
                $name,
            ));
        }
    }

    /**
     * Plain prose must reach the fast path whether or not the author hard
     * wrapped it. The synthetic corpus is written on single lines, so only a
     * wrapped case proves the reach.
     */
    public function testPlainProseReachesTheFastPathWhenWrapped(): void
    {
        $paragraph = trim(str_repeat('the quick brown fox jumps over a lazy dog ', 12));

        foreach (['une ligne' => $paragraph, 'replie' => wordwrap($paragraph, 60)] as $shape => $markdown) {
            Instrumentation::measure();
            Markdown::gfm()->toHtml($markdown . "\n", null, self::options());

            self::assertGreaterThan(0, Instrumentation::$plainScanHits, \sprintf(
                'Plain prose (%s) fell through to the rich inline path.',
                $shape,
            ));
            self::assertSame(0, Instrumentation::$fusedInlineRenders, \sprintf(
                'Plain prose (%s) built inline state it does not need.',
                $shape,
            ));
        }
    }

    /**
     * A counter that stops counting is as harmful as a wrong one: it makes an
     * attribution look clean. Each input below must move its counter.
     */
    public function testCountersStayWiredToTheirPaths(): void
    {
        Instrumentation::measure();
        Markdown::gfm()->toHtml('<span title="x">a</span> b' . "\n", null, self::options());

        self::assertGreaterThan(0, Instrumentation::$rawHtmlCandidates, self::blind('rawHtmlCandidates', 'inline raw HTML'));
        self::assertGreaterThan(0, Instrumentation::$rawHtmlScanBytes, self::blind('rawHtmlScanBytes', 'inline raw HTML'));

        Instrumentation::measure();
        Markdown::gfm()->toHtml('*a* **b** *c* **d**' . "\n", null, self::options());

        self::assertGreaterThan(0, Instrumentation::$fusedInlineRenders, self::blind('fusedInlineRenders', 'rich inline content'));
        self::assertGreaterThan(0, Instrumentation::$escapeCalls, self::blind('escapeCalls', 'text that needs escaping'));

        Instrumentation::measure();
        Markdown::gfm()->toHtml("[a]: /url\n\nsee [a]\n", null, self::options());

        self::assertGreaterThan(0, Instrumentation::$blockRefdefScanBytes, self::blind('blockRefdefScanBytes', 'a reference definition'));
    }

    /**
     * Fragmenting a document must cost per block, never per block times the
     * document. Splitting the same body bytes into 40 times as many fenced
     * blocks may raise the per-line consult counts by a constant factor, not
     * by a factor that tracks the input size.
     *
     * The counters below are the block loop's own work: how often a construct
     * is asked to start, how often the cursor is asked for its first
     * non-space, and how far the column walker travels. PD.1 found the
     * fragmented case paying a fixed cost per block that made the code-block
     * family 2.3x Parsedown while homogeneous input sat at 1.3x. Milliseconds
     * cannot gate that on a shared machine; these can.
     */
    public function testFragmentationCostsPerBlockNotPerDocument(): void
    {
        $bodyLines = 400;
        $body = '$value = $collection->map(static fn ($x) => $x + 1);';
        $counts = [];

        foreach ([10, 400] as $blocks) {
            $perBlock = intdiv($bodyLines, $blocks);
            $lines = [];

            for ($block = 0; $block < $blocks; ++$block) {
                $lines[] = '```php';

                for ($line = 0; $line < $perBlock; ++$line) {
                    $lines[] = $body;
                }

                $lines[] = '```';
                $lines[] = '';
            }

            $markdown = implode("\n", $lines);
            Instrumentation::measure();
            Markdown::gfm()->toHtml($markdown, null, self::options());

            $counts[$blocks] = [
                'lines' => substr_count($markdown, "\n") + 1,
                'tryStart' => Instrumentation::$blockTryStartCalls,
                'fns' => Instrumentation::$blockFnsCalls,
                'columnWalk' => Instrumentation::$blockColumnAtWalkBytes,
            ];
        }

        self::assertGreaterThan(0, $counts[400]['tryStart'], self::blind('blockTryStartCalls', 'fenced code blocks'));
        self::assertGreaterThan(0, $counts[400]['fns'], self::blind('blockFnsCalls', 'any block content'));

        // One start probe per opening fence, not per line of the document.
        self::assertSame(400, $counts[400]['tryStart'], \sprintf(
            '400 fenced blocks cost %d start probes. A probe per line rather than per block is ' .
            'the fragmentation trap this budget exists for.',
            $counts[400]['tryStart'],
        ));

        // Fragmenting multiplies the line count by 3.2 here; the per-line
        // cursor work must follow the line count, not outrun it.
        $lineGrowth = $counts[400]['lines'] / $counts[10]['lines'];
        $fnsGrowth = $counts[400]['fns'] / max(1, $counts[10]['fns']);

        self::assertLessThan(2.0 * $lineGrowth, $fnsGrowth, \sprintf(
            'Fragmenting to 400 blocks grew the line count %.2fx but the first-non-space ' .
            'queries %.2fx (%d to %d). The block loop is asking per block per line.',
            $lineGrowth,
            $fnsGrowth,
            $counts[10]['fns'],
            $counts[400]['fns'],
        ));

        // Code content never needs a column: a root fence keeps one span.
        self::assertSame(0, $counts[400]['columnWalk'], \sprintf(
            'Root fenced code walked %d bytes of column arithmetic. Contiguous spans exist so ' .
            'it walks none.',
            $counts[400]['columnWalk'],
        ));
    }

    private static function blind(string $counter, string $input): string
    {
        return \sprintf(
            'Counter %s stayed at zero on input containing %s. It is no longer wired to the path ' .
            'it claims to measure, so any attribution reading it is silently wrong.',
            $counter,
            $input,
        );
    }

    /**
     * Reference definitions are extracted when a paragraph closes. The
     * scanner must look at the paragraph, not at the document: the version
     * before PL/W4.3 copied and examined about two thirds of the input on
     * every conversion.
     */
    public function testReferenceExtractionStaysBoundedByItsParagraphs(): void
    {
        $definitions = '';

        for ($i = 0; $i < 200; ++$i) {
            $definitions .= \sprintf("[ref%d]: /url/%d\n", $i, $i);
        }

        $markdown = $definitions . "\n" . str_repeat("Some prose that mentions nothing in particular.\n\n", 200);

        Instrumentation::measure();
        Markdown::gfm()->toHtml($markdown, null, self::options());

        self::assertLessThan(\strlen($markdown), Instrumentation::$blockRefdefScanBytes, \sprintf(
            'Reference extraction examined %d bytes of a %d byte document. It is rescanning ' .
            'beyond the paragraph being closed.',
            Instrumentation::$blockRefdefScanBytes,
            \strlen($markdown),
        ));
    }

    /**
     * Emphasis resolution is the classic quadratic trap. The openers-bottom
     * bound keeps the search linear; doubling the input must not quadruple
     * the work.
     */
    public function testEmphasisSearchStaysLinear(): void
    {
        $steps = [];

        // Matched pairs: every closer walks back to its opener, so the
        // counter measures real search work.
        foreach ([1000, 2000, 4000] as $size) {
            Instrumentation::measure();
            Markdown::gfm()->toHtml(str_repeat('*x* ', $size) . "\n", null, self::options());
            $steps[$size] = Instrumentation::$delimiterSearchSteps;
        }

        // A blind counter reports zero everywhere, and zero over zero looks
        // perfectly linear. Prove it is counting before trusting the ratios.
        self::assertGreaterThan(0, $steps[4000], self::blind('delimiterSearchSteps', 'matched emphasis pairs'));

        foreach ([2000, 4000] as $size) {
            $growth = $steps[$size / 2] > 0 ? $steps[$size] / $steps[$size / 2] : 1.0;

            self::assertLessThan(2.5, $growth, \sprintf(
                'Delimiter search grew %.2fx when the input doubled to %d (%d steps to %d). ' .
                'Linear behaviour doubles; anything approaching 4x is quadratic.',
                $growth,
                $size,
                $steps[$size / 2],
                $steps[$size],
            ));
        }
    }

    /**
     * The openers-bottom bound is what turned the 16,000-delimiter adversarial
     * case from 8.4 seconds into 157 milliseconds. Unmatched closers must not
     * rescan the stack: once a class of opener is known absent, every later
     * closer of that class searches nothing at all.
     */
    public function testUnmatchedDelimitersDoNotRescanTheStack(): void
    {
        Instrumentation::measure();
        Markdown::gfm()->toHtml(str_repeat('a* ', 4000) . "\n", null, self::options());

        self::assertLessThan(4000, Instrumentation::$delimiterSearchSteps, \sprintf(
            '4,000 unmatched closers cost %d search steps. The openers-bottom bound is not ' .
            'holding, which is the quadratic emphasis trap.',
            Instrumentation::$delimiterSearchSteps,
        ));
    }

    /**
     * The direct lane exists to skip edit machinery. If a workspace, an
     * inline cache or a journal appears here, the lane separation has been
     * quietly undone.
     */
    public function testDirectLaneBuildsNoEditMachinery(): void
    {
        Instrumentation::measure();
        Markdown::gfm()->toHtml("# Title\n\nSome *rich* text with a [link](/url).\n", null, self::options());

        self::assertSame(1, Instrumentation::$syntaxParses, 'Direct conversion parsed the source more than once.');
        self::assertSame(0, Instrumentation::$documentWorkspaces, 'Direct conversion built a document workspace.');
        self::assertSame(0, Instrumentation::$inlineCaches, 'Direct conversion built an inline cache.');
        self::assertSame(0, Instrumentation::$editJournals, 'Direct conversion built an edit journal.');
        self::assertSame(0, Instrumentation::$nodeHandles, 'Direct conversion built public node handles.');
    }

    /**
     * The fused emitter falls back to the tape renderer for GFM nested
     * strong. That fallback must stay rare: it is a correctness escape
     * hatch, not a path real documents take.
     */
    public function testFusedFallbackDoesNotFireOnOrdinaryContent(): void
    {
        $markdown = "# Title\n\n" . wordwrap(trim(str_repeat('some prose with `code`, *emphasis*, a [link](/url) and **strong** text. ', 20)), 78) . "\n";

        Instrumentation::measure();
        Markdown::gfm()->toHtml($markdown, null, self::options());

        self::assertSame(0, Instrumentation::$fusedInlineFallbacks, \sprintf(
            'The fused emitter fell back %d times on ordinary content: %s',
            Instrumentation::$fusedInlineFallbacks,
            json_encode(Instrumentation::$fusedFallbackReasons),
        ));
    }

    /**
     * Spec policy, so the budgets describe the same work the conformance
     * corpora and the benchmark harness measure.
     */
    private static function options(): RenderOptions
    {
        return new RenderOptions(htmlPolicy: HtmlPolicy::spec());
    }
}
