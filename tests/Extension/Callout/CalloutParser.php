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

namespace Alto\Markdown\Tests\Extension\Callout;

use Alto\Markdown\Parser\Block\BlockConstruct;
use Alto\Markdown\Parser\Block\BlockStart;
use Alto\Markdown\Parser\Block\ContinueResult;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;

/**
 * Fenced callout container used by the public extension guide.
 *
 *     :::note
 *     Body text, parsed as ordinary blocks.
 *     :::
 *
 * Up to three columns of indentation, three or more ":" bytes, an optional
 * ASCII label, and nothing else on the line. The block is a container, so its
 * body goes through the normal three-phase loop. It closes on a fence of at
 * least the opening length carrying no label, or at end of input.
 *
 * The opening fence length rides on the tape node's flags column and the label
 * on its payload column; a construct gets no per-block storage of its own from
 * the loop.
 */
final class CalloutParser implements BlockConstruct
{
    private const int COLON = 0x3A;

    public function kind(): int
    {
        return CalloutKind::CALLOUT;
    }

    public function triggerBytes(): string
    {
        return ':';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        $fence = $this->matchFence($state);

        if (null === $fence) {
            return null;
        }

        [$length, $label] = $fence;

        if ('' === $label) {
            return null;
        }

        return new BlockStart(
            CalloutKind::CALLOUT,
            $state->lineContentEnd,
            isContainer: true,
            flags: $length,
            payload: $label,
        );
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $fence = $this->matchFence($state);

        if (null === $fence) {
            return ContinueResult::Matched;
        }

        [$length, $label] = $fence;

        if ('' !== $label || $length < $state->tape->flags($ordinal)) {
            return ContinueResult::Matched;
        }

        $state->tape->setEndOffset($ordinal, $state->lineContentEnd);

        return ContinueResult::Closed;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        $tape = $state->tape;
        $end = $tape->endOffset($ordinal);
        $child = $tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $childEnd = $tape->endOffset($child);

            if ($childEnd > $end) {
                $end = $childEnd;
            }

            $child = $tape->nextSiblingOrdinal($child);
        }

        $tape->setEndOffset($ordinal, max($end, $tape->startOffset($ordinal)));
    }

    /**
     * The fence on the current line as (marker length, label), or null when
     * the line is not a fence. The label is the trimmed remainder and is the
     * empty string on a closing fence.
     *
     * @return array{int, string}|null
     */
    private function matchFence(ParserState $state): ?array
    {
        $first = $state->firstNonSpaceFrom();

        if ($state->cursorIndentFrom($first) > 3) {
            return null;
        }

        $buffer = $state->buffer;
        $end = $state->lineContentEnd;
        $offset = $first;

        while ($offset < $end && self::COLON === $buffer->byteAt($offset)) {
            ++$offset;
        }

        $length = $offset - $first;

        if ($length < 3) {
            return null;
        }

        $label = trim($buffer->substring($offset, $end));

        if ('' !== $label && 1 !== preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $label)) {
            return null;
        }

        return [$length, strtolower($label)];
    }
}
