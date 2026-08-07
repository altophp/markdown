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
use Alto\Markdown\Parser\ParseTape;

/**
 * Lists (SPEC section "Lists"). A LIST wraps a run of same-signature list
 * items: it starts when an item marker appears and the enclosing container
 * is not already a compatible list, and it stops matching when a marker of
 * a different signature appears at its level, so the loop closes it and a
 * new list opens as its sibling.
 *
 * Flags: bit 0 ordered, bit 1 loose (decided at close), bits 8+ carry the
 * marker signature byte. Payload: the start number for ordered lists (the
 * renderer reads it; bullets carry none).
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ListParser implements BlockConstruct
{
    private const int ORDERED = 1;
    private const int LOOSE = 2;

    public function kind(): int
    {
        return BlockKind::LIST;
    }

    public function triggerBytes(): string
    {
        return '-+*0123456789';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        $marker = ListItemParser::matchMarker($state);

        if (null === $marker) {
            return null;
        }

        $tape = $state->tape;

        if (BlockKind::LIST === $tape->kindId($containerOrdinal) && ($tape->flags($containerOrdinal) >> 8) === $marker['signature']) {
            // A compatible list is already open; the item starts directly.
            return null;
        }

        if ($paragraphOpen) {
            // Interrupting a paragraph: ordered lists must start at 1 and
            // empty items never interrupt.
            if ($marker['empty'] || ($marker['ordered'] && 1 !== $marker['start'])) {
                return null;
            }
        }

        return new BlockStart(
            BlockKind::LIST,
            $state->offset,
            isContainer: true,
            flags: ($marker['ordered'] ? self::ORDERED : 0) | ($marker['signature'] << 8),
            payload: $marker['ordered'] ? (string) $marker['start'] : null,
        );
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        if ($state->lineIsBlank) {
            return ContinueResult::Matched;
        }

        $marker = ListItemParser::matchMarker($state);

        if (null !== $marker) {
            $lastItem = $this->lastOpenItem($state, $ordinal);

            if (ParseTape::NONE !== $lastItem
                && ParseTape::NONE === $state->tape->endOffset($lastItem)
                && $state->cursorIndent() >= $state->tape->flags($lastItem)
            ) {
                return ContinueResult::Matched;
            }

            // Same signature: a sibling item will start. Different
            // signature: this list ends and a sibling list opens.
            return ($state->tape->flags($ordinal) >> 8) === $marker['signature']
                ? ContinueResult::Matched
                : ContinueResult::NotMatched;
        }

        // No marker: the line stays in the list only when it is content of
        // the last item, which must still be open (no end offset yet) and
        // reached by the line's indentation.
        $tape = $state->tape;
        $lastItem = $this->lastOpenItem($state, $ordinal);

        if (ParseTape::NONE === $lastItem || ParseTape::NONE !== $tape->endOffset($lastItem)) {
            return ContinueResult::NotMatched;
        }

        $fns = $state->firstNonSpaceFrom();

        if ($fns >= $state->lineContentEnd) {
            return ContinueResult::Matched;
        }

        // The item content column is stored relative to the enclosing
        // container, so compare against the indentation past the cursor
        // (where outer block quote markers left off), not the absolute column.
        return $state->cursorIndentFrom($fns) >= $tape->flags($lastItem)
            ? ContinueResult::Matched
            : ContinueResult::NotMatched;
    }

    private function lastOpenItem(ParserState $state, int $ordinal): int
    {
        $tape = $state->tape;
        $lastItem = ParseTape::NONE;
        $child = $tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $lastItem = $child;
            $child = $tape->nextSiblingOrdinal($child);
        }

        return $lastItem;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        $tape = $state->tape;
        $end = $tape->startOffset($ordinal);
        $loose = false;

        $item = $tape->firstChildOrdinal($ordinal);
        $previousEnd = ParseTape::NONE;

        while (ParseTape::NONE !== $item) {
            $itemEnd = $tape->endOffset($item);

            if ($itemEnd > $end) {
                $end = $itemEnd;
            }

            // Blank lines are the ones the loop treated as blank, which
            // includes marker-only lines inside containers that raw source
            // inspection cannot see.
            if (ParseTape::NONE !== $previousEnd && $state->hasBlankLineBetween($previousEnd, $tape->startOffset($item))) {
                $loose = true;
            }

            // Blank line between two blocks directly inside one item.
            $block = $tape->firstChildOrdinal($item);

            while (ParseTape::NONE !== $block) {
                $next = $tape->nextSiblingOrdinal($block);

                if (ParseTape::NONE !== $next && $state->hasBlankLineBetween($tape->endOffset($block), $tape->startOffset($next))) {
                    $loose = true;
                }

                $block = $next;
            }

            $previousEnd = $itemEnd;
            $item = $tape->nextSiblingOrdinal($item);
        }

        $tape->setEndOffset($ordinal, $end);

        if ($loose) {
            $tape->addFlags($ordinal, self::LOOSE);
        }
    }
}
