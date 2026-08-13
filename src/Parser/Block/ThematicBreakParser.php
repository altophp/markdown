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
 * Thematic breaks (CommonMark 0.31.2, "Thematic breaks").
 *
 * Up to three spaces of indentation, then three or more matching `-`, `_`,
 * or `*` markers, each optionally followed by spaces or tabs, and nothing
 * else on the line. Thematic breaks may interrupt a paragraph, so this
 * construct ignores $paragraphOpen; setext underlines (`-`) win only
 * because the registry consults SetextHeadingParser first.
 *
 * Single line: tryContinue finalizes on the start line and closes.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ThematicBreakParser implements BlockConstruct
{
    public function kind(): int
    {
        return BlockKind::THEMATIC_BREAK;
    }

    public function triggerBytes(): string
    {
        return '-_*';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        $first = $state->firstNonSpaceFrom();

        if ($state->cursorIndentFrom($first) > 3) {
            return null;
        }

        $buffer = $state->buffer;
        $marker = $buffer->byteAt($first);

        if (0x2A !== $marker && 0x2D !== $marker && 0x5F !== $marker) {
            return null;
        }

        $end = $state->lineContentEnd;
        $count = 0;

        for ($offset = $first; $offset < $end; ++$offset) {
            $byte = $buffer->byteAt($offset);

            if ($byte === $marker) {
                ++$count;

                continue;
            }

            if (0x20 !== $byte && 0x09 !== $byte) {
                return null;
            }
        }

        if ($count < 3) {
            return null;
        }

        return new BlockStart(BlockKind::THEMATIC_BREAK, $first);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $state->tape->setEndOffset($ordinal, $state->lineContentEnd);

        return ContinueResult::Closed;
    }

    public function close(ParserState $state, int $ordinal): void {}
}
