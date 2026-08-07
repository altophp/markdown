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

namespace Alto\Markdown\Tests\Support;

use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Source\SourceDocument;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\Assert;

final class SourceEditPropertyHarness
{
    public function assertFidelity(SourceEditExpectation $expectation, MarkdownFactory $factory): void
    {
        $expected = $factory->fromString($expectation->expectedSemanticBytes);
        $actual = $factory->fromString($expectation->editedBytes);
        $comparison = new SemanticTreeComparator()->compare($expected->model(), $actual->model());

        Assert::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function assertPreservation(SourceEditExpectation $expectation): void
    {
        Assert::assertSame(
            $this->preservedSegments($expectation->originalBytes, $expectation->originalTouchedRanges),
            $this->preservedSegments($expectation->editedBytes, $expectation->editedTouchedRanges),
            'Untouched byte segments must stay byte-identical.',
        );
    }

    public function assertMinimality(SourceEditExpectation $expectation): void
    {
        $originalAllowed = $this->allowedLines($expectation->originalBytes, $expectation->originalTouchedRanges);
        $editedAllowed = $this->allowedLines($expectation->editedBytes, $expectation->editedTouchedRanges);
        [$changedOriginal, $changedEdited] = $this->changedLineIndexes($expectation->originalBytes, $expectation->editedBytes);

        foreach ($changedOriginal as $line) {
            Assert::assertArrayHasKey($line, $originalAllowed, \sprintf('Original line %d changed outside the edited block range.', $line + 1));
        }

        foreach ($changedEdited as $line) {
            Assert::assertArrayHasKey($line, $editedAllowed, \sprintf('Edited line %d changed outside the edited block range.', $line + 1));
        }
    }

    public function assertProperties(SourceEditExpectation $expectation, MarkdownFactory $factory): void
    {
        $this->assertFidelity($expectation, $factory);
        $this->assertMinimality($expectation);
        $this->assertPreservation($expectation);
    }

    public function assertSourceRangeBytes(SourceDocument $source, SourceRange $range, string $expectedBytes): void
    {
        Assert::assertSame($expectedBytes, new SourceBuffer($source->bytes)->slice($range));
    }

    public function assertUnifiedDiffFixture(
        string $expected,
        string $originalBytes,
        string $editedBytes,
        string $from = 'original',
        string $to = 'edited',
    ): void {
        Assert::assertSame($expected, $this->unifiedDiff($originalBytes, $editedBytes, $from, $to));
    }

    /**
     * @param list<SourceRange> $ranges
     *
     * @return list<string>
     */
    private function preservedSegments(string $bytes, array $ranges): array
    {
        $segments = [];
        $cursor = 0;

        foreach ($this->normalizedRanges($ranges, \strlen($bytes)) as $range) {
            if ($cursor < $range->startOffset) {
                $segments[] = \substr($bytes, $cursor, $range->startOffset - $cursor);
            }

            $cursor = $range->endOffset;
        }

        if ($cursor < \strlen($bytes)) {
            $segments[] = \substr($bytes, $cursor);
        }

        return $segments;
    }

    /**
     * @param list<SourceRange> $ranges
     *
     * @return array<int, true>
     */
    private function allowedLines(string $bytes, array $ranges): array
    {
        $scanner = new LineScanner(new SourceBuffer($bytes));
        $allowed = [];

        foreach ($this->normalizedRanges($ranges, \strlen($bytes)) as $range) {
            foreach ($this->linesOverlappingRange($scanner, $range) as $line) {
                $allowed[$line] = true;
            }
        }

        return $allowed;
    }

    /**
     * @return list<int>
     */
    private function linesOverlappingRange(LineScanner $scanner, SourceRange $range): array
    {
        if (0 === $scanner->lineCount()) {
            return [];
        }

        if ($range->startOffset === $range->endOffset) {
            return [$this->lineContainingOffset($scanner, $range->startOffset)];
        }

        $lines = [];

        for ($line = 0; $line < $scanner->lineCount(); ++$line) {
            if ($range->startOffset < $scanner->lineEnd($line) && $range->endOffset > $scanner->contentStart($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function lineContainingOffset(LineScanner $scanner, int $offset): int
    {
        for ($line = 0; $line < $scanner->lineCount(); ++$line) {
            if ($offset <= $scanner->lineEnd($line)) {
                return $line;
            }
        }

        return $scanner->lineCount() - 1;
    }

    /**
     * @param list<SourceRange> $ranges
     *
     * @return list<SourceRange>
     */
    private function normalizedRanges(array $ranges, int $length): array
    {
        foreach ($ranges as $range) {
            Assert::assertGreaterThanOrEqual(0, $range->startOffset);
            Assert::assertGreaterThanOrEqual($range->startOffset, $range->endOffset);
            Assert::assertLessThanOrEqual($length, $range->endOffset);
        }

        usort($ranges, static fn (SourceRange $left, SourceRange $right): int => $left->startOffset <=> $right->startOffset);

        $merged = [];

        foreach ($ranges as $range) {
            $lastIndex = \count($merged) - 1;
            $last = $lastIndex >= 0 ? $merged[$lastIndex] : null;

            if (!$last instanceof SourceRange || $range->startOffset > $last->endOffset) {
                $merged[] = $range;

                continue;
            }

            $merged[$lastIndex] = new SourceRange($last->startOffset, \max($last->endOffset, $range->endOffset));
        }

        return $merged;
    }

    /**
     * @return array{list<int>, list<int>}
     */
    private function changedLineIndexes(string $originalBytes, string $editedBytes): array
    {
        $original = $this->splitLines($originalBytes);
        $edited = $this->splitLines($editedBytes);
        [$matchedOriginal, $matchedEdited] = $this->matchedLineIndexes($original, $edited);
        $changedOriginal = [];
        $changedEdited = [];

        foreach (array_keys($original) as $line) {
            if (!isset($matchedOriginal[$line])) {
                $changedOriginal[] = $line;
            }
        }

        foreach (array_keys($edited) as $line) {
            if (!isset($matchedEdited[$line])) {
                $changedEdited[] = $line;
            }
        }

        return [$changedOriginal, $changedEdited];
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return array{array<int, true>, array<int, true>}
     */
    private function matchedLineIndexes(array $original, array $edited): array
    {
        $lengths = $this->lcsLengths($original, $edited);
        $matchedOriginal = [];
        $matchedEdited = [];
        $i = 0;
        $j = 0;

        while ($i < \count($original) && $j < \count($edited)) {
            if ($original[$i] === $edited[$j]) {
                $matchedOriginal[$i] = true;
                $matchedEdited[$j] = true;
                ++$i;
                ++$j;

                continue;
            }

            if (($lengths[$i + 1][$j] ?? 0) >= ($lengths[$i][$j + 1] ?? 0)) {
                ++$i;

                continue;
            }

            ++$j;
        }

        return [$matchedOriginal, $matchedEdited];
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return array<int, array<int, int>>
     */
    private function lcsLengths(array $original, array $edited): array
    {
        $originalCount = \count($original);
        $editedCount = \count($edited);
        $lengths = array_fill(0, $originalCount + 1, array_fill(0, $editedCount + 1, 0));

        for ($i = $originalCount - 1; $i >= 0; --$i) {
            for ($j = $editedCount - 1; $j >= 0; --$j) {
                if ($original[$i] === $edited[$j]) {
                    $lengths[$i][$j] = $lengths[$i + 1][$j + 1] + 1;

                    continue;
                }

                $lengths[$i][$j] = \max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        return $lengths;
    }

    private function unifiedDiff(string $originalBytes, string $editedBytes, string $from, string $to): string
    {
        $original = $this->splitLines($originalBytes);
        $edited = $this->splitLines($editedBytes);
        $lengths = $this->lcsLengths($original, $edited);
        $out = ["--- {$from}", "+++ {$to}", \sprintf('@@ -1,%d +1,%d @@', \count($original), \count($edited))];
        $i = 0;
        $j = 0;

        while ($i < \count($original) && $j < \count($edited)) {
            if ($original[$i] === $edited[$j]) {
                $out[] = ' '.$this->stripLineEnding($original[$i]);
                ++$i;
                ++$j;

                continue;
            }

            if (($lengths[$i + 1][$j] ?? 0) >= ($lengths[$i][$j + 1] ?? 0)) {
                $out[] = '-'.$this->stripLineEnding($original[$i]);
                ++$i;

                continue;
            }

            $out[] = '+'.$this->stripLineEnding($edited[$j]);
            ++$j;
        }

        while ($i < \count($original)) {
            $out[] = '-'.$this->stripLineEnding($original[$i]);
            ++$i;
        }

        while ($j < \count($edited)) {
            $out[] = '+'.$this->stripLineEnding($edited[$j]);
            ++$j;
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $bytes): array
    {
        if ('' === $bytes) {
            return [];
        }

        $lines = [];
        $offset = 0;
        $length = \strlen($bytes);

        while ($offset < $length) {
            $start = $offset;
            $offset += strcspn($bytes, "\r\n", $offset);

            if ($offset < $length) {
                if ("\r" === $bytes[$offset] && $offset + 1 < $length && "\n" === $bytes[$offset + 1]) {
                    $offset += 2;
                } else {
                    ++$offset;
                }
            }

            $lines[] = \substr($bytes, $start, $offset - $start);
        }

        return $lines;
    }

    private function stripLineEnding(string $line): string
    {
        return preg_replace('/\r\n|\r|\n$/', '', $line) ?? $line;
    }
}
