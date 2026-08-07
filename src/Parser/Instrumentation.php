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

namespace Alto\Markdown\Parser;

/**
 * Test-only counters proving the laziness rule: reading blocks must not
 * pay for inline parsing. Static by design so the production call sites
 * stay dependency-free; tests reset between assertions.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Instrumentation
{
    public static int $syntaxParses = 0;

    public static int $documentWorkspaces = 0;

    public static int $workspaceTapeClones = 0;

    public static int $referenceMapClones = 0;

    public static int $inlineCaches = 0;

    public static int $editJournals = 0;

    public static int $inlineParses = 0;

    public static int $inlineMarkdownReconstructions = 0;

    public static int $inlineContentBuilds = 0;

    public static int $inlineOffsetSegmentAdvances = 0;

    public static int $inlineOffsetFallbackSearches = 0;

    public static int $inlineParserConstructions = 0;

    public static bool $trackInlineComplexity = false;

    public static int $delimiterSearchSteps = 0;

    public static int $inlineSiblingWalkSteps = 0;

    public static int $cacheHits = 0;

    /**
     * Warm rich table cell HTML cache outcomes: a hit reuses a rendered cell,
     * a miss renders and stores it. Proves cross-render cell reuse.
     */
    public static int $tableCellHtmlHits = 0;

    public static int $tableCellHtmlMisses = 0;

    public static int $nodeHandles = 0;

    public static int $headingHandles = 0;

    public static int $linkHandles = 0;

    public static int $imageHandles = 0;

    public static int $codeBlockHandles = 0;

    /**
     * Opaque code payload materializations. Decorated rendering must read a
     * code block once, then reuse the value for native HTML and decorators.
     */
    public static int $codeBlockCodeReads = 0;

    public static int $sectionHandles = 0;

    public static int $traversals = 0;

    public static int $extensionFormatterContexts = 0;

    public static int $extensionStatsEvents = 0;

    /**
     * Public inline parser attempts after byte dispatch. With no public inline
     * extension installed this remains zero. With extensions installed it is
     * bounded by matching trigger occurrences times parsers sharing that byte.
     */
    public static int $extensionInlineParserAttempts = 0;

    /**
     * Custom inline renderer lookups. Core-only parsing and rendering must
     * leave this at zero.
     */
    public static int $extensionInlineRendererLookups = 0;

    /**
     * Native HTML decorator calls after compiled kind dispatch. A profile
     * without decorators must leave this at zero.
     */
    public static int $htmlDecoratorInvocations = 0;

    /**
     * Semantic destination nodes offered to a compiled rewrite pipeline.
     * Profiles without a destination rewriter must leave this at zero.
     */
    public static int $linkDestinationRewrites = 0;

    public static int $documentTransformPlans = 0;

    public static int $documentTransformFactories = 0;

    public static int $documentTransformInvocations = 0;

    public static int $documentTransformBlockViews = 0;

    public static int $documentTransformHeadingViews = 0;

    /**
     * Master switch for per-stage first-render attribution (PL.0). When
     * false the enter()/leave() timers and the guarded escape counters are
     * never touched, so a normal conversion pays only these static bool
     * reads. Turn it on around one direct conversion, read the totals, then
     * turn it off.
     */
    public static bool $timing = false;

    /**
     * Accumulated exclusive (self) time in nanoseconds per stage. A nested
     * stage pauses its parent, so the values partition the conversion.
     *
     * @var array<string, int>
     */
    public static array $stageNanos = [];

    /**
     * Number of enter() calls per stage: how many blocks or constructs each
     * stage was measured over.
     *
     * @var array<string, int>
     */
    public static array $stageEnters = [];

    /**
     * Active region frames as [stage, resumedAtNanos].
     *
     * @var list<array{string, int}>
     */
    private static array $regionStack = [];

    public static int $escapeCalls = 0;

    public static int $escapeBytes = 0;

    public static int $plainScanHits = 0;

    /**
     * Block-phase counters (PL.2b). Guarded by $timing at every call site,
     * so a normal parse pays only static bool reads. They split block-parse
     * work below the stage timers: cursor services, construct consults, and
     * per-paragraph bookkeeping.
     */
    public static int $blockFnsCalls = 0;

    public static int $blockFnsMemoHits = 0;

    public static int $blockColumnAtCalls = 0;

    public static int $blockColumnAtWalkBytes = 0;

    public static int $blockTryContinueCalls = 0;

    public static int $blockTryStartCalls = 0;

    public static int $blockListMarkerScans = 0;

    public static int $blockListMarkerMemoHits = 0;

    public static int $blockParagraphAppends = 0;

    /**
     * Paragraphs offered to the reference definition scanner on close.
     */
    public static int $blockRefdefScans = 0;

    /**
     * Source bytes the reference definition scanner reads (W4.3). It scans the
     * buffer in place, so this counts what each attempt touched: the span of
     * every accepted definition plus the prefix of every rejection, not the
     * length of the paragraph the attempt started in.
     */
    public static int $blockRefdefScanBytes = 0;

    /**
     * Reference-resolution and inline raw-HTML counters (PL.2c). Guarded by
     * $timing at every call site, so a normal parse pays only static bool
     * reads. They split link-resolve and inline-scan work: match attempts,
     * label lookups, case-fold effort, and raw-HTML tag probing.
     */
    public static int $refMatchCalls = 0;

    public static int $refInlineSuffix = 0;

    public static int $refLabelLookups = 0;

    public static int $refLookupHits = 0;

    public static int $refLookupMisses = 0;

    public static int $refFailed = 0;

    public static int $normalizeCalls = 0;

    public static int $normalizeFastPath = 0;

    public static int $caseFoldCalls = 0;

    public static int $caseFoldBytes = 0;

    public static int $rawHtmlCandidates = 0;

    public static int $rawHtmlMatches = 0;

    public static int $rawHtmlScanBytes = 0;

    /**
     * Fused direct-lane inline renders attempted (per rich block or rich
     * table cell).
     */
    public static int $fusedInlineRenders = 0;

    /**
     * Fused renders that fell back to the tape path.
     */
    public static int $fusedInlineFallbacks = 0;

    /**
     * Fallbacks per construct reason, e.g. "nested-strong".
     *
     * @var array<string, int>
     */
    public static array $fusedFallbackReasons = [];

    private function __construct()
    {
    }

    /**
     * Opens a timed stage. The currently active stage, if any, is paused so
     * that time spent inside the child is not double counted; the parent
     * clock resumes on leave(). Call only when $timing is true.
     */
    public static function enter(string $stage): void
    {
        $now = (int) hrtime(true);
        $depth = \count(self::$regionStack);

        if ($depth > 0) {
            $parent = self::$regionStack[$depth - 1];
            self::$stageNanos[$parent[0]] = (self::$stageNanos[$parent[0]] ?? 0) + ($now - $parent[1]);
        }

        self::$regionStack[] = [$stage, $now];
        self::$stageEnters[$stage] = (self::$stageEnters[$stage] ?? 0) + 1;
    }

    /**
     * Closes the innermost timed stage and resumes its parent's clock. Call
     * only when $timing is true, balanced with enter().
     */
    public static function leave(string $stage): void
    {
        $now = (int) hrtime(true);
        $region = array_pop(self::$regionStack);

        if (null === $region) {
            return;
        }

        self::$stageNanos[$region[0]] = (self::$stageNanos[$region[0]] ?? 0) + ($now - $region[1]);
        $depth = \count(self::$regionStack);

        if ($depth > 0) {
            self::$regionStack[$depth - 1][1] = $now;
        }
    }

    /**
     * Current nesting depth of timed regions, for balanced unwinding when
     * a fused render aborts mid-stage.
     */
    public static function regionDepth(): int
    {
        return \count(self::$regionStack);
    }

    /**
     * Closes open regions until the stack is back at $depth, crediting
     * each closed stage normally. Call only when $timing is true.
     */
    public static function unwindRegions(int $depth): void
    {
        while (\count(self::$regionStack) > $depth) {
            $region = self::$regionStack[\count(self::$regionStack) - 1];
            self::leave($region[0]);
        }
    }

    /**
     * Arms every counter and clears the totals, in the order that cannot
     * silently produce zeros.
     *
     * Two separate switches guard the counters, and arming only one is a
     * quiet way to measure nothing: $timing covers the stage timers and most
     * counters, $trackInlineComplexity covers the delimiter and sibling-walk
     * budgets. Reading a zero from an unarmed counter looks exactly like a
     * clean result, so callers should reach for this rather than the flags.
     */
    public static function measure(): void
    {
        self::reset();
        self::$timing = true;
        self::$trackInlineComplexity = true;
    }

    public static function disable(): void
    {
        self::$timing = false;
        self::$trackInlineComplexity = false;
    }

    public static function reset(): void
    {
        self::$syntaxParses = 0;
        self::$documentWorkspaces = 0;
        self::$workspaceTapeClones = 0;
        self::$referenceMapClones = 0;
        self::$inlineCaches = 0;
        self::$editJournals = 0;
        self::$inlineParses = 0;
        self::$inlineMarkdownReconstructions = 0;
        self::$inlineContentBuilds = 0;
        self::$inlineOffsetSegmentAdvances = 0;
        self::$inlineOffsetFallbackSearches = 0;
        self::$inlineParserConstructions = 0;
        // Like $timing, this states intent to measure, not a measurement.
        self::$delimiterSearchSteps = 0;
        self::$inlineSiblingWalkSteps = 0;
        self::$cacheHits = 0;
        self::$tableCellHtmlHits = 0;
        self::$tableCellHtmlMisses = 0;
        self::$nodeHandles = 0;
        self::$headingHandles = 0;
        self::$linkHandles = 0;
        self::$imageHandles = 0;
        self::$codeBlockHandles = 0;
        self::$codeBlockCodeReads = 0;
        self::$sectionHandles = 0;
        self::$traversals = 0;
        self::$extensionFormatterContexts = 0;
        self::$extensionStatsEvents = 0;
        self::$extensionInlineParserAttempts = 0;
        self::$extensionInlineRendererLookups = 0;
        self::$htmlDecoratorInvocations = 0;
        self::$linkDestinationRewrites = 0;
        self::$documentTransformPlans = 0;
        self::$documentTransformFactories = 0;
        self::$documentTransformInvocations = 0;
        self::$documentTransformBlockViews = 0;
        self::$documentTransformHeadingViews = 0;
        // $timing is deliberately NOT cleared here. It states the caller's
        // intent to measure, not a measurement, and clearing it made
        // "arm the flag, reset, convert, read" silently report zeros. Use
        // disable() to turn measurement off.
        self::$stageNanos = [];
        self::$stageEnters = [];
        self::$regionStack = [];
        self::$escapeCalls = 0;
        self::$escapeBytes = 0;
        self::$plainScanHits = 0;
        self::$blockFnsCalls = 0;
        self::$blockFnsMemoHits = 0;
        self::$blockColumnAtCalls = 0;
        self::$blockColumnAtWalkBytes = 0;
        self::$blockTryContinueCalls = 0;
        self::$blockTryStartCalls = 0;
        self::$blockListMarkerScans = 0;
        self::$blockListMarkerMemoHits = 0;
        self::$blockParagraphAppends = 0;
        self::$blockRefdefScans = 0;
        self::$blockRefdefScanBytes = 0;
        self::$refMatchCalls = 0;
        self::$refInlineSuffix = 0;
        self::$refLabelLookups = 0;
        self::$refLookupHits = 0;
        self::$refLookupMisses = 0;
        self::$refFailed = 0;
        self::$normalizeCalls = 0;
        self::$normalizeFastPath = 0;
        self::$caseFoldCalls = 0;
        self::$caseFoldBytes = 0;
        self::$rawHtmlCandidates = 0;
        self::$rawHtmlMatches = 0;
        self::$rawHtmlScanBytes = 0;
        self::$fusedInlineRenders = 0;
        self::$fusedInlineFallbacks = 0;
        self::$fusedFallbackReasons = [];
    }
}
