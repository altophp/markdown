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
use Alto\Markdown\Source\LineEnding;
use Alto\Markdown\Source\SourceRange;

/**
 * Splits a SourceBuffer into lines.
 *
 * Recognizes the three CommonMark line endings: "\n" (Lf), "\r\n" (CrLf), and a
 * lone "\r" (Cr) (SPEC section 8). Scanning starts at the buffer content start,
 * so a leading BOM is skipped while offsets stay honest to the original bytes
 * (SPEC section 9). A trailing line ending never produces a phantom empty line.
 *
 * Per line it records the content start offset, the content end offset (before
 * the EOL bytes), the line end offset (after the EOL bytes), and the LineEnding
 * (null for a final line with no ending). The dominant EOL is the most frequent
 * ending; when counts tie the preference order is Lf, then CrLf, then Cr, so a
 * document with no ending resolves to Lf (SPEC section 8).
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class LineScanner
{
    /** @var list<int> */
    private array $starts = [];

    /** @var list<int> */
    private array $contentEnds = [];

    /** @var list<int> */
    private array $lineEnds = [];

    /** @var list<LineEnding|null> */
    private array $eols = [];

    private readonly int $lineCount;

    private readonly LineEnding $dominantEol;

    public function __construct(SourceBuffer $buffer)
    {
        $bytes = $buffer->bytes;
        $length = $buffer->length;

        $lf = 0;
        $crlf = 0;
        $cr = 0;

        $offset = $buffer->contentStart();

        // Local accumulators: the loop runs once per input line, so appending
        // through a helper method cost one userland call per line for nothing
        // (PD.1). The columns are published once, after the scan.
        $starts = [];
        $contentEnds = [];
        $lineEnds = [];
        $eols = [];

        while ($offset < $length) {
            $starts[] = $offset;
            $offset += strcspn($bytes, "\r\n", $offset);
            $contentEnds[] = $offset;

            if ($offset >= $length) {
                $lineEnds[] = $offset;
                $eols[] = null;

                break;
            }

            if ("\r" === $bytes[$offset]) {
                if ($offset + 1 < $length && "\n" === $bytes[$offset + 1]) {
                    $offset += 2;
                    ++$crlf;
                    $lineEnds[] = $offset;
                    $eols[] = LineEnding::CrLf;

                    continue;
                }

                ++$offset;
                ++$cr;
                $lineEnds[] = $offset;
                $eols[] = LineEnding::Cr;

                continue;
            }

            ++$offset;
            ++$lf;
            $lineEnds[] = $offset;
            $eols[] = LineEnding::Lf;
        }

        $this->starts = $starts;
        $this->contentEnds = $contentEnds;
        $this->lineEnds = $lineEnds;
        $this->eols = $eols;
        $this->lineCount = \count($starts);
        $this->dominantEol = self::resolveDominant($lf, $crlf, $cr);
    }

    public function lineCount(): int
    {
        return $this->lineCount;
    }

    /**
     * All content start offsets, indexed by line. Bulk accessor for the
     * block loop's per-line cursor bookkeeping (copy-on-write, no copy).
     *
     * @return list<int>
     */
    public function contentStarts(): array
    {
        return $this->starts;
    }

    /**
     * All content end offsets, indexed by line.
     *
     * @return list<int>
     */
    public function contentEnds(): array
    {
        return $this->contentEnds;
    }

    /**
     * All line end offsets (EOL bytes included), indexed by line.
     *
     * @return list<int>
     */
    public function lineEnds(): array
    {
        return $this->lineEnds;
    }

    /**
     * Content start offset of the line (first byte of the line).
     */
    public function contentStart(int $line): int
    {
        if ($line < 0 || $line >= $this->lineCount) {
            $this->throwLine($line);
        }

        return $this->starts[$line];
    }

    /**
     * Content end offset of the line (first byte of the EOL, or end of input).
     */
    public function contentEnd(int $line): int
    {
        if ($line < 0 || $line >= $this->lineCount) {
            $this->throwLine($line);
        }

        return $this->contentEnds[$line];
    }

    /**
     * Line end offset (first byte of the next line, or end of input).
     */
    public function lineEnd(int $line): int
    {
        if ($line < 0 || $line >= $this->lineCount) {
            $this->throwLine($line);
        }

        return $this->lineEnds[$line];
    }

    /**
     * Line ending kind, or null for a final line with no ending.
     */
    public function eol(int $line): ?LineEnding
    {
        if ($line < 0 || $line >= $this->lineCount) {
            $this->throwLine($line);
        }

        return $this->eols[$line];
    }

    /**
     * Content range of the line (start inclusive, end exclusive), EOL excluded.
     */
    public function contentRange(int $line): SourceRange
    {
        if ($line < 0 || $line >= $this->lineCount) {
            $this->throwLine($line);
        }

        return new SourceRange($this->starts[$line], $this->contentEnds[$line]);
    }

    public function dominantEol(): LineEnding
    {
        return $this->dominantEol;
    }

    private static function resolveDominant(int $lf, int $crlf, int $cr): LineEnding
    {
        $best = LineEnding::Lf;
        $bestCount = $lf;

        if ($crlf > $bestCount) {
            $best = LineEnding::CrLf;
            $bestCount = $crlf;
        }

        if ($cr > $bestCount) {
            $best = LineEnding::Cr;
        }

        return $best;
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
