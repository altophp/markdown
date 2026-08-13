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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Parser\Instrumentation;

/**
 * A block's inline content as one scannable string: trimmed line slices
 * joined with "\n", plus the map back to original source offsets. Inline
 * constructs can span lines (code spans, raw HTML), so scanning must see
 * the whole block; source ranges on the tape stay original-byte through
 * translate().
 *
 * Hard-break bytes (trailing spaces, the break backslash) are excluded
 * from the content; the break kind per joint is precomputed.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InlineContent
{
    private int $segmentCursor = 0;

    private int $lastTranslatedOffset = 0;

    /**
     * @param string    $text       the joined content
     * @param list<int> $starts     content offset of each segment
     * @param list<int> $lengths    byte length of each segment
     * @param list<int> $sources    source offset of each segment start
     * @param list<int> $breakKinds InlineKind per joint (after segment i)
     * @param list<int> $breakChops text bytes a break strips before the joint
     */
    private function __construct(
        public readonly string $text,
        private readonly array $starts,
        private readonly array $lengths,
        private readonly array $sources,
        public readonly array $breakKinds,
        public readonly array $breakChops,
    ) {}

    /**
     * A shared empty content: the pre-render placeholder state of reusable
     * consumers. Not a build, so the instrumentation counter stays quiet.
     */
    public static function none(): self
    {
        return new self('', [], [], [], [], []);
    }

    /**
     * Segments are RAW line slices: trailing spaces and break backslashes
     * stay in the content, because a code span crossing the joint keeps
     * them. Only when the parser loop renders a joint as a break do the
     * precomputed chop bytes disappear from the preceding text.
     *
     * @param list<array{int, int, int}> $pairs content ranges (start, end, pad)
     */
    public static function fromPairs(string $bytes, array $pairs): self
    {
        ++Instrumentation::$inlineContentBuilds;
        $text = '';
        $starts = [];
        $lengths = [];
        $sources = [];
        $breakKinds = [];
        $breakChops = [];
        $last = \count($pairs) - 1;

        foreach ($pairs as $index => [$start, $end]) {
            if ($index > 0) {
                $text .= "\n";
            }

            $starts[] = \strlen($text);
            $lengths[] = $end - $start;
            $sources[] = $start;
            $text .= substr($bytes, $start, $end - $start);

            if ($index === $last) {
                break;
            }

            // The joint's break kind and how many trailing bytes it strips:
            // a run of 2+ spaces (hard), a lone break backslash after an
            // odd run (hard, strips the backslash), or a soft break
            // stripping trailing spaces. Only U+0020 spaces are stripped at
            // a line ending (spec 6.7 hard, 6.9 soft): a trailing tab is
            // literal content and terminates the run.
            $spaces = 0;

            while ($end - 1 - $spaces >= $start) {
                if (' ' !== $bytes[$end - 1 - $spaces]) {
                    break;
                }

                ++$spaces;
            }

            if ($spaces >= 2 && ' ' === $bytes[$end - 1] && ' ' === $bytes[$end - 2]) {
                $breakKinds[] = InlineKind::HARD_BREAK;
                $breakChops[] = $spaces;

                continue;
            }

            if (0 === $spaces && $end > $start && '\\' === $bytes[$end - 1]) {
                $run = 0;

                while ($end - 1 - $run >= $start && '\\' === $bytes[$end - 1 - $run]) {
                    ++$run;
                }

                if (1 === $run % 2) {
                    $breakKinds[] = InlineKind::HARD_BREAK;
                    $breakChops[] = 1;

                    continue;
                }
            }

            $breakKinds[] = InlineKind::SOFT_BREAK;
            $breakChops[] = $spaces;
        }

        return new self($text, $starts, $lengths, $sources, $breakKinds, $breakChops);
    }

    /**
     * Original source offset for a content offset. A joint "\n" (or a
     * position past a segment end) maps to the end of the segment before
     * it, so exclusive end offsets translate correctly.
     */
    public function sourceOffset(int $contentOffset): int
    {
        $segment = $this->segmentAt($contentOffset);
        $within = min($contentOffset - $this->starts[$segment], $this->lengths[$segment]);

        return $this->sources[$segment] + $within;
    }

    /**
     * The joint index whose "\n" sits at this content offset, for looking
     * up the break kind.
     */
    public function jointAt(int $contentOffset): int
    {
        return min($this->segmentAt($contentOffset), \count($this->breakKinds) - 1);
    }

    private function segmentAt(int $contentOffset): int
    {
        $count = \count($this->starts);

        if ($contentOffset < $this->lastTranslatedOffset) {
            ++Instrumentation::$inlineOffsetFallbackSearches;
            $lo = 0;
            $hi = $count - 1;

            while ($lo < $hi) {
                $mid = ($lo + $hi + 1) >> 1;

                if ($this->starts[$mid] <= $contentOffset) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }

            $this->segmentCursor = $lo;
        } else {
            while ($this->segmentCursor + 1 < $count && $this->starts[$this->segmentCursor + 1] <= $contentOffset) {
                ++$this->segmentCursor;
                ++Instrumentation::$inlineOffsetSegmentAdvances;
            }
        }

        $this->lastTranslatedOffset = $contentOffset;

        return $this->segmentCursor;
    }
}
