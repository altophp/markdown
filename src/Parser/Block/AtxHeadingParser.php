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

namespace Alto\Markdown\Parser\Block;

use Alto\Markdown\Parser\ParserState;

/**
 * ATX headings (CommonMark 0.31.2, "ATX headings").
 *
 * Up to three spaces of indentation, then 1..6 `#` markers, then a space,
 * tab, or end of line. An optional closing run of `#` markers is stripped
 * only when preceded by a space or tab (or when it is the whole content).
 * ATX headings interrupt paragraphs, so this construct ignores
 * $paragraphOpen.
 *
 * Tape: flags = level 1..6; payload = "cs:ce" content byte range with the
 * opening markers, their trailing space, and any closing run excluded.
 * Single line: tryContinue finalizes on the start line and closes.
 *
 * Scanning reads the buffer bytes directly rather than through the guarded
 * accessor: a heading is one whole block per line, so its marker and trim
 * scans sat squarely in the per-block fixed cost (PD.1). Every offset here is
 * bounded by the line's content end, which the accessor's range check would
 * have accepted anyway.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class AtxHeadingParser implements BlockConstruct
{
    private const string HASH = '#';

    public function kind(): int
    {
        return BlockKind::ATX_HEADING;
    }

    public function triggerBytes(): string
    {
        return '#';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        $first = $state->firstNonSpaceFrom();

        if ($state->cursorIndentFrom($first) > 3) {
            return null;
        }

        $bytes = $state->buffer->bytes;
        $end = $state->lineContentEnd;
        $level = strspn($bytes, self::HASH, $first, $end - $first);

        if ($level < 1 || $level > 6) {
            return null;
        }

        $offset = $first + $level;

        if ($offset < $end) {
            $after = $bytes[$offset];

            if (' ' !== $after && "\t" !== $after) {
                return null;
            }
        }

        return new BlockStart(BlockKind::ATX_HEADING, $first);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $bytes = $state->buffer->bytes;
        $tape = $state->tape;
        $end = $state->lineContentEnd;
        $start = $state->offset;
        $level = strspn($bytes, self::HASH, $start, $end - $start);
        $offset = $start + $level;

        $contentStart = $offset + strspn($bytes, " \t", $offset, $end - $offset);
        $contentEnd = $this->trimTrailingSpace($bytes, $contentStart, $end);
        $contentEnd = $this->stripClosingRun($bytes, $contentStart, $contentEnd);

        $tape->setFlags($ordinal, $level);
        $tape->setEndOffset($ordinal, $end);
        $tape->setPayload($ordinal, $contentStart . ':' . $contentEnd);

        return ContinueResult::Closed;
    }

    public function close(ParserState $state, int $ordinal): void {}

    private function trimTrailingSpace(string $bytes, int $start, int $end): int
    {
        while ($end > $start) {
            $byte = $bytes[$end - 1];

            if (' ' !== $byte && "\t" !== $byte) {
                break;
            }

            --$end;
        }

        return $end;
    }

    /**
     * Strip an optional trailing run of `#` markers when it is preceded by a
     * space or tab, or spans the whole content; then trim the space left
     * between the content and the removed run.
     */
    private function stripClosingRun(string $bytes, int $start, int $end): int
    {
        $run = $end;

        while ($run > $start && self::HASH === $bytes[$run - 1]) {
            --$run;
        }

        if ($run === $end) {
            return $end;
        }

        if ($run !== $start) {
            $before = $bytes[$run - 1];

            if (' ' !== $before && "\t" !== $before) {
                return $end;
            }
        }

        return $this->trimTrailingSpace($bytes, $start, $run);
    }
}
