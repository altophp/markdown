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

namespace Alto\Markdown\Parser\Input;

use Alto\Markdown\Exception\SourcePositionException;

/**
 * Per-line indentation map over a LineScanner.
 *
 * For each line it records the first non-space-non-tab byte offset and that
 * byte's virtual column, computed with four-column tab stops. Tabs are never
 * expanded in the buffer; the column is virtual (SPEC section 7). A tab advances
 * the column to the next multiple of four.
 *
 * A blank line contains only spaces and tabs (or nothing). For a blank line the
 * first non-space offset is the line content end and the column is the full
 * virtual width of the indentation.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class LineMap
{
    /** @var list<int> */
    private array $firstNonSpaceOffsets = [];

    /** @var list<int> */
    private array $firstNonSpaceColumns = [];

    /** @var list<bool> */
    private array $blank = [];

    private readonly int $lineCount;

    public function __construct(SourceBuffer $buffer, LineScanner $scanner)
    {
        $bytes = $buffer->bytes;
        $count = $scanner->lineCount();
        // Bulk line geometry instead of two guarded accessor calls per line:
        // the map is built once per parse over every line, so the accessors
        // dominated its cost on line-dense input (PD.1).
        $starts = $scanner->contentStarts();
        $ends = $scanner->contentEnds();

        for ($line = 0; $line < $count; ++$line) {
            $start = $starts[$line];
            $end = $ends[$line];
            // Space-only indentation is the overwhelming case and its column
            // span equals its byte span, so one strspn answers both.
            $span = strspn($bytes, ' ', $start, $end - $start);
            $offset = $start + $span;
            $column = $span;

            if ($offset < $end && "\t" === $bytes[$offset]) {
                while ($offset < $end) {
                    $byte = $bytes[$offset];

                    if (' ' === $byte) {
                        ++$column;
                        ++$offset;

                        continue;
                    }

                    if ("\t" === $byte) {
                        $column += 4 - ($column % 4);
                        ++$offset;

                        continue;
                    }

                    break;
                }
            }

            $this->firstNonSpaceOffsets[] = $offset;
            $this->firstNonSpaceColumns[] = $column;
            $this->blank[] = $offset >= $end;
        }

        $this->lineCount = $count;
    }

    public function lineCount(): int
    {
        return $this->lineCount;
    }

    /**
     * All first non-space offsets, indexed by line. Bulk accessor for the
     * block loop's per-line cursor bookkeeping (copy-on-write, no copy).
     *
     * @return list<int>
     */
    public function allFirstNonSpaceOffsets(): array
    {
        return $this->firstNonSpaceOffsets;
    }

    /**
     * All first non-space virtual columns, indexed by line.
     *
     * @return list<int>
     */
    public function allFirstNonSpaceColumns(): array
    {
        return $this->firstNonSpaceColumns;
    }

    /**
     * All blank-line flags, indexed by line.
     *
     * @return list<bool>
     */
    public function blankFlags(): array
    {
        return $this->blank;
    }

    /**
     * Offset of the first non-space-non-tab byte, or the content end for a blank line.
     */
    public function firstNonSpaceOffset(int $line): int
    {
        if ($line < 0 || $line >= $this->lineCount) {
            $this->throwLine($line);
        }

        return $this->firstNonSpaceOffsets[$line];
    }

    /**
     * Virtual column of the first non-space-non-tab byte, with four-column tab stops.
     */
    public function firstNonSpaceColumn(int $line): int
    {
        if ($line < 0 || $line >= $this->lineCount) {
            $this->throwLine($line);
        }

        return $this->firstNonSpaceColumns[$line];
    }

    /**
     * Whether the line holds only spaces and tabs (or nothing).
     */
    public function isBlank(int $line): bool
    {
        if ($line < 0 || $line >= $this->lineCount) {
            $this->throwLine($line);
        }

        return $this->blank[$line];
    }

    /**
     * @throws SourcePositionException always; callers inline the range test so
     *                                 the hot path never enters a frame
     */
    private function throwLine(int $line): never
    {
        throw new SourcePositionException(\sprintf('Line index %d is out of range [0, %d).', $line, $this->lineCount));
    }
}
