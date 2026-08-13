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

use Alto\Markdown\Exception\SourcePositionException;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;

/**
 * Shared cursor over the input during the block phase: which line, which
 * byte within it, plus the scanning services and the tape under
 * construction. Constructs consume markers by advancing the cursor; the
 * core loop owns line transitions. T2.3 may add methods; existing
 * signatures are frozen for wave B.
 *
 * Per-line facts (content bounds, blankness, first non-space) are mirrored
 * from LineScanner and LineMap into plain properties on every line change,
 * so the hot block loop reads them without method dispatch or per-call
 * range asserts (PL.2b).
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ParserState
{
    /**
     * Index of the current line. Public read so the hot constructs skip an
     * accessor frame; only line transitions write it.
     */
    public private(set) int $line = 0;

    /**
     * Byte offset of the cursor. Public read so the block loop can tell
     * whether a phase moved the cursor without paying an accessor frame; only
     * the cursor methods write it.
     */
    public private(set) int $offset;

    /**
     * Columns of pad owed by partially consumed tabs. Public read so the hot
     * recorders skip the accessor frame in the overwhelming case where no tab
     * was partially consumed; taking the pad still clears it.
     */
    public private(set) int $pendingPad = 0;

    /**
     * Content start offset of the current line, mirrored on line change.
     */
    private int $lineStart;

    /**
     * Content end offset of the current line (before the EOL bytes),
     * mirrored on line change. Public read for the block loop and the
     * constructs; only line transitions write it.
     */
    public private(set) int $lineContentEnd;

    /**
     * End offset of the current line, EOL bytes included, mirrored on line
     * change. Equal to lineContentEnd only on a final line with no ending,
     * which is exactly the LineScanner "no EOL" case (PD.1).
     */
    public private(set) int $lineEnd;

    /**
     * Whether the current line holds only spaces and tabs (or nothing),
     * mirrored on line change.
     */
    public private(set) bool $lineIsBlank;

    /**
     * @var list<int>
     */
    private readonly array $lineStarts;

    /**
     * @var list<int>
     */
    private readonly array $lineContentEnds;

    /**
     * @var list<int>
     */
    private readonly array $lineEnds;

    /**
     * @var list<bool>
     */
    private readonly array $lineBlankFlags;

    /**
     * @var list<int>
     */
    private readonly array $lineFirstNonSpaceOffsets;

    /**
     * @var list<int>
     */
    private readonly array $lineFirstNonSpaceColumns;

    /**
     * Column anchor for the current line: the column at $colAnchorOffset,
     * both reset on line change. A column depends only on the bytes from
     * the line's content start, so any computed pair is a valid anchor and
     * queries walk from it instead of from the line start. Queries are
     * near-monotonic during parsing, which makes columnAt amortized O(1)
     * per line instead of O(line length) per call.
     */
    private int $colAnchorOffset = 0;

    private int $colAnchorColumn = 0;

    private int $fnsCacheOffset = -1;

    private int $fnsCacheResult = -1;

    /**
     * List marker memo (PL.2b): the loop and the list constructs each ask
     * ListItemParser::matchMarker() at the same cursor position on the same
     * line, so the last scan is memoized keyed by cursor offset and pending
     * pad. Offsets never repeat across lines within one parse, which makes
     * the pair a sound key.
     */
    private int $markerMemoOffset = -1;

    private int $markerMemoPad = -1;

    /**
     * @var array{contentOffset: int, contentColumn: int, signature: int, ordered: bool, start: int, empty: bool, pad?: int}|null
     */
    private ?array $markerMemoValue = null;

    /**
     * Start offsets of lines the loop treated as blank, in parse order.
     * Includes container-relative blanks (only markers on the line), which
     * raw source inspection cannot see; looseness decisions read this.
     *
     * @var list<int>
     */
    private array $blankLineStarts = [];

    public function __construct(
        public readonly SourceBuffer $buffer,
        private readonly LineScanner $scanner,
        private readonly LineMap $map,
        public readonly ParseTape $tape,
    ) {
        $this->offset = $buffer->contentStart();
        $this->colAnchorOffset = $this->offset;
        $this->lineStarts = $scanner->contentStarts();
        $this->lineContentEnds = $scanner->contentEnds();
        $this->lineEnds = $scanner->lineEnds();
        $this->lineBlankFlags = $map->blankFlags();
        $this->lineFirstNonSpaceOffsets = $map->allFirstNonSpaceOffsets();
        $this->lineFirstNonSpaceColumns = $map->allFirstNonSpaceColumns();

        if ($scanner->lineCount() > 0) {
            $this->lineStart = $this->lineStarts[0];
            $this->lineContentEnd = $this->lineContentEnds[0];
            $this->lineEnd = $this->lineEnds[0];
            $this->lineIsBlank = $this->lineBlankFlags[0];
            $this->fnsCacheOffset = $this->offset;
            $this->fnsCacheResult = $this->lineFirstNonSpaceOffsets[0];
            $this->colAnchorOffset = $this->fnsCacheResult;
            $this->colAnchorColumn = $this->lineFirstNonSpaceColumns[0];
        } else {
            $this->lineStart = $this->offset;
            $this->lineContentEnd = $this->offset;
            $this->lineEnd = $this->offset;
            $this->lineIsBlank = true;
        }
    }

    public function buffer(): SourceBuffer
    {
        return $this->buffer;
    }

    public function scanner(): LineScanner
    {
        return $this->scanner;
    }

    public function map(): LineMap
    {
        return $this->map;
    }

    public function tape(): ParseTape
    {
        return $this->tape;
    }

    public function line(): int
    {
        return $this->line;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * Move the cursor forward within the current line. Never moves backward
     * and never past the line end.
     */
    public function advanceTo(int $offset): void
    {
        if ($offset < $this->offset || $offset > $this->lineContentEnd) {
            throw new SourcePositionException(\sprintf('Cannot advance from %d to %d on line %d (content ends at %d).', $this->offset, $offset, $this->line, $this->lineContentEnd));
        }

        $this->offset = $offset;
    }

    /**
     * Advance to the next line; returns false when input is exhausted.
     */
    public function nextLine(): bool
    {
        $line = $this->line + 1;

        if ($line >= \count($this->lineStarts)) {
            return false;
        }

        $this->line = $line;
        $offset = $this->lineStarts[$line];
        $this->offset = $offset;
        $this->pendingPad = 0;
        $this->lineStart = $offset;
        $this->lineContentEnd = $this->lineContentEnds[$line];
        $this->lineEnd = $this->lineEnds[$line];
        $this->lineIsBlank = $this->lineBlankFlags[$line];
        // Seed the memoized cursor services from the line map: the first
        // non-space offset and its column are precomputed per line, so the
        // first query on the line costs no scan.
        $this->fnsCacheOffset = $offset;
        $this->fnsCacheResult = $this->lineFirstNonSpaceOffsets[$line];
        $this->colAnchorOffset = $this->fnsCacheResult;
        $this->colAnchorColumn = $this->lineFirstNonSpaceColumns[$line];

        return true;
    }

    /**
     * Consume whitespace up to a target virtual column. A tab that spans
     * past the target is consumed whole and its excess columns become
     * pending pad: virtual spaces the next content-range recorder must
     * prepend (SPEC section 7, partial tab consumption).
     */
    public function advanceToColumn(int $column): void
    {
        $end = $this->lineContentEnd;
        $bytes = $this->buffer->bytes;

        while ($this->offset < $end && $this->columnAt($this->offset) < $column) {
            $byte = $bytes[$this->offset];

            if (' ' === $byte) {
                ++$this->offset;

                continue;
            }

            if ("\t" !== $byte) {
                break;
            }

            $before = $this->columnAt($this->offset);
            $after = $before + 4 - ($before % 4);
            ++$this->offset;

            if ($after > $column) {
                $this->pendingPad += $after - $column;
            }
        }
    }

    /**
     * Records that the current line acts as a blank line (raw blank, or
     * only container markers before its end).
     */
    public function markLineBlank(): void
    {
        $this->blankLineStarts[] = $this->lineStart;
    }

    /**
     * Whether any line treated as blank starts within [$from, $to).
     */
    public function hasBlankLineBetween(int $from, int $to): bool
    {
        $lo = 0;
        $hi = \count($this->blankLineStarts);

        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;

            if ($this->blankLineStarts[$mid] < $from) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo < \count($this->blankLineStarts) && $this->blankLineStarts[$lo] < $to;
    }

    /**
     * Columns of pad owed by partially consumed tabs; reading resets it.
     */
    public function takePendingPad(): int
    {
        $pad = $this->pendingPad;
        $this->pendingPad = 0;

        return $pad;
    }

    /**
     * Drops virtual spaces that have just been consumed as block-marker
     * indentation rather than as block content.
     */
    public function discardPendingPad(): void
    {
        $this->pendingPad = 0;
    }

    /**
     * Adds pad columns owed by a marker that consumed part of a tab.
     */
    public function addPendingPad(int $columns): void
    {
        $this->pendingPad += $columns;
    }

    /**
     * The column where the current container's content begins on this
     * line: the cursor's column minus pending pad, so indentation math
     * treats partially consumed tabs as if the pad columns were real
     * spaces sitting before the cursor.
     */
    public function baseColumn(): int
    {
        return $this->columnAt($this->offset) - $this->pendingPad;
    }

    public function atLineEnd(): bool
    {
        return $this->offset >= $this->lineContentEnd;
    }

    /**
     * Indentation in columns from the cursor to the first non-space byte,
     * pending pad included: the value every construct compares against the
     * 4-column code threshold. Equal to columnAt(firstNonSpaceFrom())
     * minus baseColumn(), computed in one call: the whitespace run is
     * all spaces in the overwhelmingly common case, where the column span
     * equals the byte span and no column walk is needed (PL.2b).
     */
    public function cursorIndent(): int
    {
        return $this->cursorIndentFrom($this->firstNonSpaceFrom());
    }

    /**
     * The same indent for a caller that already holds the first non-space
     * offset. Nearly every construct queries both, and asking twice cost a
     * second frame for an answer already in hand (PD.1).
     */
    public function cursorIndentFrom(int $fns): int
    {
        $offset = $this->offset;
        $span = $fns - $offset;

        if (0 === $span) {
            return $this->pendingPad;
        }

        if (strspn($this->buffer->bytes, ' ', $offset, $span) === $span) {
            return $span + $this->pendingPad;
        }

        // Tabs in the run: query the cursor column first so the second
        // query walks forward from the fresh anchor.
        $base = $this->columnAt($offset);

        return $this->columnAt($fns) - $base + $this->pendingPad;
    }

    /**
     * Offset of the first byte at or after the cursor that is not a space
     * or tab; the line's content end when only whitespace remains.
     * Memoized per cursor position: every construct asks this on every
     * line, so the answer is computed once per cursor move.
     */
    public function firstNonSpaceFrom(): int
    {
        if (Instrumentation::$timing) {
            ++Instrumentation::$blockFnsCalls;
        }

        if ($this->fnsCacheOffset === $this->offset) {
            if (Instrumentation::$timing) {
                ++Instrumentation::$blockFnsMemoHits;
            }

            return $this->fnsCacheResult;
        }

        $end = $this->lineContentEnd;
        $result = min($end, $this->offset + strspn($this->buffer->bytes, " \t", $this->offset, $end - $this->offset));

        $this->fnsCacheOffset = $this->offset;
        $this->fnsCacheResult = $result;

        return $result;
    }

    /**
     * The memoized list marker scan at the current cursor position, keyed
     * by cursor offset and pending pad. Returns true when a scan result
     * (possibly "no marker") is stored for this exact position.
     */
    public function hasListMarkerMemo(): bool
    {
        return $this->markerMemoOffset === $this->offset && $this->markerMemoPad === $this->pendingPad;
    }

    /**
     * @return array{contentOffset: int, contentColumn: int, signature: int, ordered: bool, start: int, empty: bool, pad?: int}|null
     */
    public function listMarkerMemo(): ?array
    {
        return $this->markerMemoValue;
    }

    /**
     * @param array{contentOffset: int, contentColumn: int, signature: int, ordered: bool, start: int, empty: bool, pad?: int}|null $marker
     */
    public function storeListMarkerMemo(?array $marker): void
    {
        $this->markerMemoOffset = $this->offset;
        $this->markerMemoPad = $this->pendingPad;
        $this->markerMemoValue = $marker;
    }

    /**
     * Virtual column of a byte offset within the current line, tabs
     * expanding to 4-column stops (SPEC section 7). Column 0 is the line's
     * content start. Amortized O(1): walks from the last computed anchor
     * on this line, falling back to the line start for backward queries.
     */
    public function columnAt(int $offset): int
    {
        $start = $this->lineStart;

        if ($offset < $start || $offset > $this->lineContentEnd) {
            throw new SourcePositionException(\sprintf('Offset %d is outside line %d (%d..%d).', $offset, $this->line, $start, $this->lineContentEnd));
        }

        if ($offset >= $this->colAnchorOffset && $this->colAnchorOffset >= $start) {
            $i = $this->colAnchorOffset;
            $column = $this->colAnchorColumn;
        } else {
            $i = $start;
            $column = 0;
        }

        if (Instrumentation::$timing) {
            ++Instrumentation::$blockColumnAtCalls;
            Instrumentation::$blockColumnAtWalkBytes += $offset - $i;
        }

        $bytes = $this->buffer->bytes;

        for (; $i < $offset; ++$i) {
            $column = "\t" === $bytes[$i] ? $column + 4 - ($column % 4) : $column + 1;
        }

        $this->colAnchorOffset = $offset;
        $this->colAnchorColumn = $column;

        return $column;
    }

    public function remainingOnLine(): string
    {
        return $this->buffer->substring($this->offset, $this->lineContentEnd);
    }
}
