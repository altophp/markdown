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

use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;

/**
 * List items (SPEC sections "List items"). An item starts with a bullet
 * (-, +, *) or ordered (1-9 digits then . or )) marker indented up to
 * three columns, followed by 1..4 spaces of separation (more means the
 * item content is an indented code block starting one column after the
 * marker). The required content column is persisted in the item's flags;
 * continuation lines must reach it.
 *
 * Items only start inside a compatible open LIST container; ListParser
 * wraps the first item of a run. Marker facts are shared through
 * ListItemParser::matchMarker().
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ListItemParser implements BlockConstruct
{
    public function kind(): int
    {
        return BlockKind::LIST_ITEM;
    }

    public function triggerBytes(): string
    {
        return '-+*0123456789';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        $tape = $state->tape;

        if (BlockKind::LIST !== $tape->kindId($containerOrdinal)) {
            return null;
        }

        $marker = self::matchMarker($state);

        if (null === $marker) {
            return null;
        }

        $listFlags = $tape->flags($containerOrdinal);

        if (($listFlags >> 8) !== $marker['signature']) {
            return null;
        }

        return new BlockStart(BlockKind::LIST_ITEM, $marker['contentOffset'], isContainer: true, flags: $marker['contentColumn'], pad: $marker['pad'] ?? 0);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        if ($state->lineIsBlank) {
            // Blank lines do not close an item with content; an item that
            // is still empty ends at its first blank line (an item may
            // begin with at most one blank line).
            return ParseTape::NONE === $state->tape->firstChildOrdinal($ordinal)
                ? ContinueResult::NotMatched
                : ContinueResult::Matched;
        }

        $required = $state->tape->flags($ordinal);
        $fns = $state->firstNonSpaceFrom();

        if ($fns >= $state->lineContentEnd) {
            return ContinueResult::Matched;
        }

        // The required column is relative to the enclosing container's
        // content start, i.e. where outer markers left the cursor on this
        // line. Block quote markers of differing widths across lines shift
        // the absolute column, so the comparison must subtract that base.
        $base = $state->baseColumn();

        if ($state->columnAt($fns) - $base < $required) {
            return ContinueResult::NotMatched;
        }

        // Consume exactly the required content columns; a tab crossing the
        // boundary leaves its excess columns as pending pad.
        $state->advanceToColumn($base + $required);

        return ContinueResult::Matched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        $tape = $state->tape;
        $end = $tape->startOffset($ordinal);
        $child = $tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $childEnd = $tape->endOffset($child);

            if ($childEnd > $end) {
                $end = $childEnd;
            }

            $child = $tape->nextSiblingOrdinal($child);
        }

        $tape->setEndOffset($ordinal, $end);
    }

    /**
     * Marker facts at the cursor, or null. The signature byte identifies
     * marker compatibility: the bullet byte itself, or the delimiter byte
     * (0x2E or 0x29) for ordered markers.
     *
     * @return array{contentOffset: int, contentColumn: int, signature: int, ordered: bool, start: int, empty: bool, pad?: int}|null
     */
    public static function matchMarker(ParserState $state): ?array
    {
        if ($state->hasListMarkerMemo()) {
            if (Instrumentation::$timing) {
                ++Instrumentation::$blockListMarkerMemoHits;
            }

            return $state->listMarkerMemo();
        }

        $marker = self::scanMarker($state);
        $state->storeListMarkerMemo($marker);

        return $marker;
    }

    /**
     * @return array{contentOffset: int, contentColumn: int, signature: int, ordered: bool, start: int, empty: bool, pad?: int}|null
     */
    private static function scanMarker(ParserState $state): ?array
    {
        if (Instrumentation::$timing) {
            ++Instrumentation::$blockListMarkerScans;
        }

        $fns = $state->firstNonSpaceFrom();
        $lineEnd = $state->lineContentEnd;

        if ($fns >= $lineEnd) {
            return null;
        }

        if ($state->cursorIndentFrom($fns) >= 4) {
            return null;
        }

        $bytes = $state->buffer->bytes;
        $byte = \ord($bytes[$fns]);
        $ordered = false;
        $start = 1;
        $markerEnd = $fns + 1;
        $signature = $byte;

        if (0x2D === $byte || 0x2B === $byte || 0x2A === $byte) {
            // Bullet marker.
        } elseif ($byte >= 0x30 && $byte <= 0x39) {
            $digits = 1;
            $number = $byte - 0x30;

            while ($markerEnd < $lineEnd && $digits <= 9) {
                $next = \ord($bytes[$markerEnd]);

                if ($next < 0x30 || $next > 0x39) {
                    break;
                }

                $number = 10 * $number + ($next - 0x30);
                ++$digits;
                ++$markerEnd;
            }

            if ($digits > 9 || $markerEnd >= $lineEnd) {
                return null;
            }

            $delimiter = \ord($bytes[$markerEnd]);

            if (0x2E !== $delimiter && 0x29 !== $delimiter) {
                return null;
            }

            ++$markerEnd;
            $ordered = true;
            $start = $number;
            $signature = $delimiter;
        } else {
            return null;
        }

        $markerColumn = $state->columnAt($fns);
        $markerWidth = $state->columnAt($markerEnd) - $markerColumn;
        // Content columns are stored relative to the enclosing container's
        // content start (where outer markers left the cursor), so a block
        // quote prefix of a different width on a later line does not shift
        // the item's required indent.
        $base = $state->baseColumn();

        // Separation: 1..4 spaces/tabs of column distance to content, or an
        // empty rest-of-line (empty item).
        $contentOffset = $markerEnd;
        $column = $markerColumn + $markerWidth;

        while ($contentOffset < $lineEnd) {
            $next = $bytes[$contentOffset];

            if (' ' !== $next && "\t" !== $next) {
                break;
            }

            ++$contentOffset;
            $column = "\t" === $next ? $column + 4 - ($column % 4) : $column + 1;
        }

        if ($contentOffset >= $lineEnd) {
            // Empty item: content column is one past the marker.
            return [
                'contentOffset' => $markerEnd,
                'contentColumn' => $markerColumn + $markerWidth + 1 - $base,
                'signature' => $signature,
                'ordered' => $ordered,
                'start' => $start,
                'empty' => true,
            ];
        }

        if ($contentOffset === $markerEnd) {
            // No separation after the marker: not a list item ("-foo").
            return null;
        }

        $separation = $column - ($markerColumn + $markerWidth);

        if ($separation > 4) {
            // Indented code inside the item: content starts one column
            // after the marker. Consuming that one column out of a leading
            // tab leaves the tab's excess columns as pad.
            $offset = $markerEnd;
            $pad = 0;

            if (' ' === $bytes[$markerEnd]) {
                $offset = $markerEnd + 1;
            } elseif ("\t" === $bytes[$markerEnd]) {
                $before = $markerColumn + $markerWidth;
                $after = $before + 4 - ($before % 4);
                $offset = $markerEnd + 1;
                $pad = $after - ($before + 1);
            }

            return [
                'contentOffset' => $offset,
                'pad' => $pad,
                'contentColumn' => $markerColumn + $markerWidth + 1 - $base,
                'signature' => $signature,
                'ordered' => $ordered,
                'start' => $start,
                'empty' => false,
            ];
        }

        return [
            'contentOffset' => $contentOffset,
            'contentColumn' => $column - $base,
            'signature' => $signature,
            'ordered' => $ordered,
            'start' => $start,
            'empty' => false,
        ];
    }
}
