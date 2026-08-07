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
 * Block quotes (SPEC section "Block quotes").
 *
 * A block quote marker is up to three columns of indentation, a ">", and one
 * optional following space or tab consumed as part of the marker. Lazy
 * paragraph continuation ("> foo\nbar") is handled by the core loop: a line
 * that carries no marker but continues an open paragraph falls through to
 * phase 3.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BlockQuoteParser implements BlockConstruct
{
    public function kind(): int
    {
        return BlockKind::BLOCK_QUOTE;
    }

    public function triggerBytes(): string
    {
        return '>';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        $marker = $this->matchMarker($state);

        if (null === $marker) {
            return null;
        }

        return new BlockStart(BlockKind::BLOCK_QUOTE, $marker['offset'], isContainer: true, pad: $marker['pad']);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $marker = $this->matchMarker($state);

        if (null === $marker) {
            return ContinueResult::NotMatched;
        }

        $state->advanceTo($marker['offset']);
        $state->discardPendingPad();

        if ($marker['pad'] > 0) {
            $state->addPendingPad($marker['pad']);
        }

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
     * Marker facts at the cursor, or null when the line carries no marker:
     * the offset just past the marker (up to three columns of indentation,
     * ">", one optional space or tab), and the pad columns owed when the
     * consumed separator was a tab (only one of its columns belongs to the
     * marker; the rest are virtual content spaces).
     *
     * @return array{offset: int, pad: int}|null
     */
    private function matchMarker(ParserState $state): ?array
    {
        $fns = $state->firstNonSpaceFrom();

        if ($state->cursorIndentFrom($fns) >= 4) {
            return null;
        }

        if (0x3E !== $state->buffer->byteAt($fns)) {
            return null;
        }

        $offset = $fns + 1;
        $pad = 0;
        $end = $state->lineContentEnd;

        if ($offset < $end) {
            $byte = $state->buffer->byteAt($offset);

            if (0x20 === $byte) {
                ++$offset;
            } elseif (0x09 === $byte) {
                $before = $state->columnAt($offset);
                $after = $before + 4 - ($before % 4);
                ++$offset;
                $pad = $after - ($before + 1);
            }
        }

        return ['offset' => $offset, 'pad' => $pad];
    }
}
