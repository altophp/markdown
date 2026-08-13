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

namespace Alto\Markdown\Operation;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Diff
{
    private const int MAX_LCS_CELLS = 1_000_000;

    /**
     * @param list<DiffHunk> $hunks
     */
    public function __construct(
        public bool $isEmpty,
        public string $unified,
        private array $hunks = [],
    ) {}

    public static function between(
        string $originalBytes,
        string $editedBytes,
        string $from = 'original',
        string $to = 'edited',
        int $context = 3,
    ): self {
        if ($originalBytes === $editedBytes) {
            return new self(true, '');
        }

        $original = self::splitLines($originalBytes);
        $edited = self::splitLines($editedBytes);
        $actions = self::diffActions($original, $edited);
        $hunks = self::buildHunks($actions, $context);
        $out = ["--- {$from}", "+++ {$to}"];

        foreach ($hunks as $hunk) {
            $out[] = \sprintf(
                '@@ -%d,%d +%d,%d @@',
                $hunk->originalStartLine,
                $hunk->originalLineCount,
                $hunk->editedStartLine,
                $hunk->editedLineCount,
            );

            foreach ($hunk->lines as $line) {
                $out[] = $line;
            }
        }

        return new self(false, implode("\n", $out) . "\n", $hunks);
    }

    public function isEmpty(): bool
    {
        return $this->isEmpty;
    }

    public function toUnifiedString(): string
    {
        return $this->unified;
    }

    /**
     * @return list<DiffHunk>
     */
    public function hunks(): array
    {
        return $this->hunks;
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $bytes): array
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

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return list<array{type: string, line: string, originalLine: int, editedLine: int}>
     */
    private static function diffActions(array $original, array $edited): array
    {
        if (self::exceedsLcsBudget(\count($original), \count($edited))) {
            return self::patienceActions($original, $edited);
        }

        return self::exactActions($original, $edited);
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return list<array{type: string, line: string, originalLine: int, editedLine: int}>
     */
    private static function exactActions(array $original, array $edited, int $originalBase = 0, int $editedBase = 0): array
    {
        $lengths = self::lcsLengths($original, $edited);
        $actions = [];
        $i = 0;
        $j = 0;

        while ($i < \count($original) && $j < \count($edited)) {
            if ($original[$i] === $edited[$j]) {
                $actions[] = ['type' => ' ', 'line' => $original[$i], 'originalLine' => $originalBase + $i + 1, 'editedLine' => $editedBase + $j + 1];
                ++$i;
                ++$j;

                continue;
            }

            if (($lengths[$i + 1][$j] ?? 0) >= ($lengths[$i][$j + 1] ?? 0)) {
                $actions[] = ['type' => '-', 'line' => $original[$i], 'originalLine' => $originalBase + $i + 1, 'editedLine' => $editedBase + $j + 1];
                ++$i;

                continue;
            }

            $actions[] = ['type' => '+', 'line' => $edited[$j], 'originalLine' => $originalBase + $i + 1, 'editedLine' => $editedBase + $j + 1];
            ++$j;
        }

        while ($i < \count($original)) {
            $actions[] = ['type' => '-', 'line' => $original[$i], 'originalLine' => $originalBase + $i + 1, 'editedLine' => $editedBase + $j + 1];
            ++$i;
        }

        while ($j < \count($edited)) {
            $actions[] = ['type' => '+', 'line' => $edited[$j], 'originalLine' => $originalBase + $i + 1, 'editedLine' => $editedBase + $j + 1];
            ++$j;
        }

        return $actions;
    }

    private static function exceedsLcsBudget(int $originalCount, int $editedCount): bool
    {
        return 0 !== $editedCount && $originalCount > intdiv(self::MAX_LCS_CELLS, $editedCount);
    }

    /**
     * Anchor large inputs on lines that occur exactly once on both sides.
     * The longest increasing anchor sequence preserves shifted regions while
     * keeping memory linear outside bounded exact subproblems.
     *
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return list<array{type: string, line: string, originalLine: int, editedLine: int}>
     */
    private static function patienceActions(array $original, array $edited, int $originalBase = 0, int $editedBase = 0): array
    {
        if (!self::exceedsLcsBudget(\count($original), \count($edited))) {
            return self::exactActions($original, $edited, $originalBase, $editedBase);
        }

        $originalCount = \count($original);
        $editedCount = \count($edited);
        $prefix = 0;

        while ($prefix < $originalCount && $prefix < $editedCount && $original[$prefix] === $edited[$prefix]) {
            ++$prefix;
        }

        $originalEnd = $originalCount;
        $editedEnd = $editedCount;

        while ($originalEnd > $prefix
            && $editedEnd > $prefix
            && $original[$originalEnd - 1] === $edited[$editedEnd - 1]
        ) {
            --$originalEnd;
            --$editedEnd;
        }

        $actions = [];

        for ($index = 0; $index < $prefix; ++$index) {
            $actions[] = [
                'type' => ' ',
                'line' => $original[$index],
                'originalLine' => $originalBase + $index + 1,
                'editedLine' => $editedBase + $index + 1,
            ];
        }

        $originalMiddle = \array_slice($original, $prefix, $originalEnd - $prefix);
        $editedMiddle = \array_slice($edited, $prefix, $editedEnd - $prefix);
        $anchors = self::patienceAnchors($originalMiddle, $editedMiddle);

        if ([] === $anchors) {
            array_push(
                $actions,
                ...(\count($originalMiddle) === \count($editedMiddle)
                    ? self::positionAlignedActions($originalMiddle, $editedMiddle, $originalBase + $prefix, $editedBase + $prefix)
                    : self::replacementActions($originalMiddle, $editedMiddle, $originalBase + $prefix, $editedBase + $prefix)),
            );
        } else {
            $previousOriginal = 0;
            $previousEdited = 0;

            foreach ($anchors as [$originalAnchor, $editedAnchor]) {
                array_push(
                    $actions,
                    ...self::patienceActions(
                        \array_slice($originalMiddle, $previousOriginal, $originalAnchor - $previousOriginal),
                        \array_slice($editedMiddle, $previousEdited, $editedAnchor - $previousEdited),
                        $originalBase + $prefix + $previousOriginal,
                        $editedBase + $prefix + $previousEdited,
                    ),
                );
                $actions[] = [
                    'type' => ' ',
                    'line' => $originalMiddle[$originalAnchor],
                    'originalLine' => $originalBase + $prefix + $originalAnchor + 1,
                    'editedLine' => $editedBase + $prefix + $editedAnchor + 1,
                ];
                $previousOriginal = $originalAnchor + 1;
                $previousEdited = $editedAnchor + 1;
            }

            array_push(
                $actions,
                ...self::patienceActions(
                    \array_slice($originalMiddle, $previousOriginal),
                    \array_slice($editedMiddle, $previousEdited),
                    $originalBase + $prefix + $previousOriginal,
                    $editedBase + $prefix + $previousEdited,
                ),
            );
        }

        for ($originalIndex = $originalEnd, $editedIndex = $editedEnd; $originalIndex < $originalCount; ++$originalIndex, ++$editedIndex) {
            $actions[] = [
                'type' => ' ',
                'line' => $original[$originalIndex],
                'originalLine' => $originalBase + $originalIndex + 1,
                'editedLine' => $editedBase + $editedIndex + 1,
            ];
        }

        return $actions;
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return list<array{type: string, line: string, originalLine: int, editedLine: int}>
     */
    private static function positionAlignedActions(array $original, array $edited, int $originalBase, int $editedBase): array
    {
        $actions = [];

        foreach ($original as $index => $line) {
            if ($line === $edited[$index]) {
                $actions[] = ['type' => ' ', 'line' => $line, 'originalLine' => $originalBase + $index + 1, 'editedLine' => $editedBase + $index + 1];

                continue;
            }

            $actions[] = ['type' => '-', 'line' => $line, 'originalLine' => $originalBase + $index + 1, 'editedLine' => $editedBase + $index + 1];
            $actions[] = ['type' => '+', 'line' => $edited[$index], 'originalLine' => $originalBase + $index + 2, 'editedLine' => $editedBase + $index + 1];
        }

        return $actions;
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return list<array{type: string, line: string, originalLine: int, editedLine: int}>
     */
    private static function replacementActions(array $original, array $edited, int $originalBase, int $editedBase): array
    {
        $actions = [];

        foreach ($original as $index => $line) {
            $actions[] = ['type' => '-', 'line' => $line, 'originalLine' => $originalBase + $index + 1, 'editedLine' => $editedBase + 1];
        }

        foreach ($edited as $index => $line) {
            $actions[] = ['type' => '+', 'line' => $line, 'originalLine' => $originalBase + \count($original) + 1, 'editedLine' => $editedBase + $index + 1];
        }

        return $actions;
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return list<array{int, int}>
     */
    private static function patienceAnchors(array $original, array $edited): array
    {
        $originalOccurrences = [];
        $editedOccurrences = [];

        foreach ($original as $index => $line) {
            if (\array_key_exists($line, $originalOccurrences)) {
                $originalOccurrences[$line] = null;
            } else {
                $originalOccurrences[$line] = $index;
            }
        }

        foreach ($edited as $index => $line) {
            if (\array_key_exists($line, $editedOccurrences)) {
                $editedOccurrences[$line] = null;
            } else {
                $editedOccurrences[$line] = $index;
            }
        }

        $pairs = [];

        foreach ($originalOccurrences as $line => $originalIndex) {
            $editedIndex = $editedOccurrences[$line] ?? null;

            if (\is_int($originalIndex) && \is_int($editedIndex)) {
                $pairs[] = [$originalIndex, $editedIndex];
            }
        }

        usort($pairs, static fn(array $left, array $right): int => $left[0] <=> $right[0]);

        $tails = [];
        $tailPairs = [];
        $previous = [];

        foreach ($pairs as $pairIndex => [, $editedIndex]) {
            $low = 0;
            $high = \count($tails);

            while ($low < $high) {
                $middle = intdiv($low + $high, 2);

                if ($tails[$middle] < $editedIndex) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            $previous[$pairIndex] = 0 === $low ? null : $tailPairs[$low - 1];
            $tails[$low] = $editedIndex;
            $tailPairs[$low] = $pairIndex;
        }

        if ([] === $tailPairs) {
            return [];
        }

        $anchors = [];
        $pairIndex = $tailPairs[\count($tailPairs) - 1] ?? null;

        if (!\is_int($pairIndex)) {
            throw new \LogicException('Patience diff produced an invalid anchor chain.');
        }

        while (true) {
            $anchors[] = $pairs[$pairIndex];
            $pairIndex = $previous[$pairIndex] ?? null;

            if (null === $pairIndex) {
                break;
            }

            if (!\is_int($pairIndex)) {
                throw new \LogicException('Patience diff produced an invalid anchor predecessor.');
            }
        }

        return \array_reverse($anchors);
    }

    /**
     * @param list<array{type: string, line: string, originalLine: int, editedLine: int}> $actions
     *
     * @return list<DiffHunk>
     */
    private static function buildHunks(array $actions, int $context): array
    {
        $changed = [];

        foreach ($actions as $index => $action) {
            if (' ' !== $action['type']) {
                $changed[] = $index;
            }
        }

        $hunks = [];
        $windowStart = null;
        $windowEnd = null;

        foreach ($changed as $index) {
            $start = \max(0, $index - $context);
            $end = \min(\count($actions) - 1, $index + $context);

            if (null === $windowStart || null === $windowEnd) {
                $windowStart = $start;
                $windowEnd = $end;

                continue;
            }

            if ($start > $windowEnd + 1) {
                $hunks[] = self::hunkFromActions(\array_slice($actions, $windowStart, $windowEnd - $windowStart + 1));
                $windowStart = $start;
                $windowEnd = $end;

                continue;
            }

            $windowEnd = \max($windowEnd, $end);
        }

        if (null !== $windowStart && null !== $windowEnd) {
            $hunks[] = self::hunkFromActions(\array_slice($actions, $windowStart, $windowEnd - $windowStart + 1));
        }

        return $hunks;
    }

    /**
     * @param list<array{type: string, line: string, originalLine: int, editedLine: int}> $actions
     */
    private static function hunkFromActions(array $actions): DiffHunk
    {
        $originalStart = null;
        $editedStart = null;
        $originalCount = 0;
        $editedCount = 0;
        $lines = [];

        foreach ($actions as $action) {
            if ('+' !== $action['type']) {
                $originalStart ??= $action['originalLine'];
                ++$originalCount;
            }

            if ('-' !== $action['type']) {
                $editedStart ??= $action['editedLine'];
                ++$editedCount;
            }

            $lines[] = $action['type'] . self::stripLineEnding($action['line']);
        }

        $first = $actions[0];
        $originalStart ??= \max(0, $first['originalLine'] - 1);
        $editedStart ??= \max(0, $first['editedLine'] - 1);

        return new DiffHunk($originalStart, $originalCount, $editedStart, $editedCount, $lines);
    }

    /**
     * @param list<string> $original
     * @param list<string> $edited
     *
     * @return array<int, array<int, int>>
     */
    private static function lcsLengths(array $original, array $edited): array
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

    private static function stripLineEnding(string $line): string
    {
        return rtrim($line, "\r\n");
    }
}
