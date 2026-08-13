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

namespace Alto\Markdown\Tests\Parser;

use Alto\Markdown\Parser\ParseTape;

/**
 * Renders a ParseTape as a stable indented text dump, one line per block in
 * document order, for snapshot comparison.
 *
 * Document order comes from the child and sibling links, never from ordinal
 * comparison, so the dump stays correct after edits reorder slots. Each line is
 * "#ordinal kind=id [start..end] flags=n" with two spaces of indent per depth,
 * and a trailing 'payload="..."' when the slot carries side-table data. The
 * output is byte-identical for identical tapes: no timestamps, no hashes, no
 * locale-dependent formatting.
 *
 * Test-only helper. It is not part of the library and lives under tests/.
 */
final class TapeDumper
{
    private const string INDENT = '  ';

    public function dump(ParseTape $tape): string
    {
        $count = $tape->count();

        if (0 === $count) {
            return '';
        }

        /** @var array<int, bool> $isSiblingTarget */
        $isSiblingTarget = [];

        for ($ordinal = 0; $ordinal < $count; ++$ordinal) {
            $next = $tape->nextSiblingOrdinal($ordinal);

            if (ParseTape::NONE !== $next) {
                $isSiblingTarget[$next] = true;
            }
        }

        /** @var list<string> $lines */
        $lines = [];

        for ($ordinal = 0; $ordinal < $count; ++$ordinal) {
            if (ParseTape::NONE === $tape->parentOrdinal($ordinal) && !isset($isSiblingTarget[$ordinal])) {
                $this->walkSiblings($tape, $ordinal, 0, $lines);
            }
        }

        if ([] === $lines) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Emit a sibling chain: each node, then its child chain one level deeper,
     * then the node's next sibling at the same level.
     *
     * @param list<string> $lines
     */
    private function walkSiblings(ParseTape $tape, int $ordinal, int $depth, array &$lines): void
    {
        while (ParseTape::NONE !== $ordinal) {
            $lines[] = $this->format($tape, $ordinal, $depth);
            $this->walkSiblings($tape, $tape->firstChildOrdinal($ordinal), $depth + 1, $lines);
            $ordinal = $tape->nextSiblingOrdinal($ordinal);
        }
    }

    private function format(ParseTape $tape, int $ordinal, int $depth): string
    {
        $line = \sprintf(
            '%s#%d kind=%d [%d..%d] flags=%d',
            \str_repeat(self::INDENT, $depth),
            $ordinal,
            $tape->kindId($ordinal),
            $tape->startOffset($ordinal),
            $tape->endOffset($ordinal),
            $tape->flags($ordinal),
        );

        $payload = $tape->payload($ordinal);

        if (null !== $payload) {
            $line .= ' payload=' . $this->quote($payload);
        }

        return $line;
    }

    private function quote(string $value): string
    {
        $escaped = \strtr($value, [
            '\\' => '\\\\',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            '"' => '\\"',
        ]);

        return '"' . $escaped . '"';
    }
}
