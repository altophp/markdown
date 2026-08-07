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
 * Setext headings (CommonMark 0.31.2, "Setext headings").
 *
 * A setext heading is an open paragraph followed by an underline: up to
 * three spaces of indentation, then a run of `=` (level 1) or `-` (level 2)
 * with no internal spaces or tabs, and only spaces or tabs after the run.
 * The underline turns the whole open paragraph into the heading, so this
 * construct fires only when $paragraphOpen is true and replaces the
 * paragraph. The registry consults it before ThematicBreakParser, so a line
 * of dashes under a paragraph is a heading, not a break.
 *
 * Tape: flags = level 1..2. The payload (the absorbed content byte range)
 * is preset by the parser loop when it absorbs the paragraph.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class SetextHeadingParser implements BlockConstruct
{
    private const int EQUALS = 0x3D;
    private const int DASH = 0x2D;
    private const int SPACE = 0x20;
    private const int TAB = 0x09;

    public function kind(): int
    {
        return BlockKind::SETEXT_HEADING;
    }

    public function triggerBytes(): string
    {
        return '=-';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        if (!$paragraphOpen) {
            return null;
        }

        $first = $state->firstNonSpaceFrom();

        if ($state->cursorIndentFrom($first) > 3) {
            return null;
        }

        $buffer = $state->buffer;
        $marker = $buffer->byteAt($first);

        if (self::EQUALS !== $marker && self::DASH !== $marker) {
            return null;
        }

        $end = $state->lineContentEnd;
        $offset = $first;

        while ($offset < $end && $marker === $buffer->byteAt($offset)) {
            ++$offset;
        }

        while ($offset < $end) {
            $byte = $buffer->byteAt($offset);

            if (self::SPACE !== $byte && self::TAB !== $byte) {
                return null;
            }

            ++$offset;
        }

        return new BlockStart(BlockKind::SETEXT_HEADING, $first, replacesParagraph: true);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $marker = $state->buffer->byteAt($state->offset);
        $level = self::EQUALS === $marker ? 1 : 2;

        $state->tape->setFlags($ordinal, $level);
        $state->tape->setEndOffset($ordinal, $state->lineContentEnd);

        return ContinueResult::Closed;
    }

    public function close(ParserState $state, int $ordinal): void
    {
    }
}
