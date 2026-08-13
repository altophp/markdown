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
 * Indented code blocks (CommonMark 0.31.2 "Indented code blocks").
 *
 * A code line is indented four or more virtual columns past the container's
 * content start. Indented code cannot interrupt a paragraph, so tryStart
 * returns null while a paragraph is open. Blank lines keep the block open but
 * never extend its content range: the end offset advances only on real code
 * lines, so trailing blanks fall outside the block while interior blanks stay
 * inside it (they are spanned once a later code line extends the range).
 *
 * Tape convention read by the renderer: payload "cs:ce" spans the first code
 * line's indentation start to the last code line's content end (its EOL
 * excluded); the renderer strips up to four columns per line, tab-aware.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class IndentedCodeParser implements BlockConstruct
{
    public function kind(): int
    {
        return BlockKind::INDENTED_CODE;
    }

    public function triggerBytes(): ?string
    {
        return null;
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        if ($paragraphOpen) {
            return null;
        }

        if ($state->lineIsBlank) {
            return null;
        }

        if ($this->indentFromCursor($state) < 4) {
            return null;
        }

        // Markers (the four-column indent) are consumed in tryContinue so the
        // first code line's indentation start is captured for the payload.
        return new BlockStart(BlockKind::INDENTED_CODE, $state->offset);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $tape = $state->tape;
        $contentEnd = $state->lineContentEnd;

        if ($state->lineIsBlank) {
            // Interior blank lines are content (recorded as pairs and
            // trimmed from the tail at close); a leading blank cannot
            // happen (starts are never blank).
            if (null !== $tape->payload($ordinal)) {
                $this->appendPair($tape, $ordinal, $state->offset, $contentEnd);
            }

            return ContinueResult::Matched;
        }

        if ($this->indentFromCursor($state) < 4) {
            return ContinueResult::NotMatched;
        }

        $this->appendPair($tape, $ordinal, $state->offset, $contentEnd, $state->takePendingPad(), $state->columnAt($state->offset));
        $tape->setEndOffset($ordinal, $contentEnd);

        // Consume the whole line so the core loop's start phase stays inert
        // over code content (a stray marker char would otherwise open a block).
        $state->advanceTo($contentEnd);

        return ContinueResult::Matched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        // Trim trailing blank-line pairs; interior blanks stay.
        $tape = $state->tape;
        $payload = $tape->payload($ordinal);

        if (null === $payload) {
            return;
        }

        $pairs = explode(';', $payload);
        $buffer = $state->buffer;

        while ([] !== $pairs) {
            $last = $pairs[\count($pairs) - 1];
            [$start, $end] = array_map('intval', explode(':', $last));

            if ('' !== trim($buffer->substring($start, $end), " \t")) {
                break;
            }

            array_pop($pairs);
        }

        $tape->setPayload($ordinal, implode(';', $pairs));
    }

    private function appendPair(\Alto\Markdown\Parser\ParseTape $tape, int $ordinal, int $start, int $end, int $pad = 0, int $column = 0): void
    {
        $pair = $start . ':' . $end . ($pad > 0 || $column > 0 ? ':' . $pad . ':' . $column : '');
        $existing = $tape->payload($ordinal);
        $tape->setPayload($ordinal, null === $existing || '' === $existing ? $pair : $existing . ';' . $pair);
    }

    private function indentFromCursor(ParserState $state): int
    {
        return $state->cursorIndent();
    }
}
