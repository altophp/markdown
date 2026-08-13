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
 * Fenced code blocks (CommonMark 0.31.2 "Fenced code blocks").
 *
 * An opening fence is three or more backticks or tildes, indented up to three
 * columns, optionally followed by an info string (backtick fences may not carry
 * backticks in the info). The block runs until a closing fence of the same
 * character, at least as long, indented up to three columns, followed only by
 * spaces or tabs; an unclosed fence runs to the end of its container. A fenced
 * block may interrupt a paragraph.
 *
 * Tape convention read by the renderer: flags = opening fence indent (0..3),
 * stripped per content line. The payload stores content ranges before `|` and
 * the trimmed info string after it. A `!` immediately before `|` records that a
 * valid closing fence was present; its absence means the container ended the
 * block.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FencedCodeParser implements OpaqueLeafBlock
{
    private const int BACKTICK = 0x60;

    /**
     * Fence parameters captured by tryStart and consumed by the first
     * tryContinue on the same line, when the block's ordinal is known.
     *
     * @var array{char: int, length: int, indent: int, info: string}|null
     */
    private ?array $pending = null;

    /**
     * Per-open-block fence state, keyed by ordinal and split into parallel
     * scalar columns. Cleared when the block closes.
     *
     * Root fences with no opening indent keep one contiguous source span.
     * Nested or indented fences retain per-line pairs because container
     * markers or indentation must be removed from their content.
     *
     * Columns rather than one struct per open fence: a content line reads two
     * of the fields and writes at most one, and a struct made every content
     * line copy the whole array (PD.1). The span end is not tracked per line
     * at all: it is the block's end offset, which every content line already
     * writes to the tape, and it is read back once on close.
     *
     * @var array<int, int>
     */
    private array $openChar = [];

    /**
     * @var array<int, int>
     */
    private array $openLength = [];

    /**
     * @var array<int, string>
     */
    private array $openInfo = [];

    /**
     * @var array<int, bool>
     */
    private array $openCompact = [];

    /**
     * @var array<int, int>
     */
    private array $openSpanStart = [];

    /**
     * @var array<int, bool>
     */
    private array $openClosed = [];

    public function kind(): int
    {
        return BlockKind::FENCED_CODE;
    }

    public function triggerBytes(): string
    {
        return '`~';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        if ($state->lineIsBlank) {
            return null;
        }

        $firstNonSpace = $state->firstNonSpaceFrom();
        $indent = $state->cursorIndentFrom($firstNonSpace);

        if ($indent >= 4) {
            return null;
        }

        $contentEnd = $state->lineContentEnd;
        $bytes = $state->buffer->bytes;

        // Direct byte reads: an opening fence is one whole block, so this scan
        // is per-block fixed cost and every offset is inside the line (PD.1).
        $fenceByte = $bytes[$firstNonSpace];

        if ('`' !== $fenceByte && '~' !== $fenceByte) {
            return null;
        }

        $char = \ord($fenceByte);
        $length = strspn($bytes, $fenceByte, $firstNonSpace, $contentEnd - $firstNonSpace);

        if ($length < 3) {
            return null;
        }

        $runEnd = $firstNonSpace + $length;
        $info = trim(substr($bytes, $runEnd, $contentEnd - $runEnd), " \t");

        if (self::BACKTICK === $char && str_contains($info, '`')) {
            return null;
        }

        $this->pending = ['char' => $char, 'length' => $length, 'indent' => $indent, 'info' => $info];

        return new BlockStart(BlockKind::FENCED_CODE, $firstNonSpace);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $tape = $state->tape;

        if (!isset($this->openChar[$ordinal])) {
            $fence = $this->pending ?? ['char' => self::BACKTICK, 'length' => 3, 'indent' => 0, 'info' => ''];
            $this->pending = null;
            $parent = $tape->parentOrdinal($ordinal);
            $this->openChar[$ordinal] = $fence['char'];
            $this->openLength[$ordinal] = $fence['length'];
            $this->openInfo[$ordinal] = $fence['info'];
            $this->openCompact[$ordinal] = 0 === $fence['indent'] && BlockKind::DOCUMENT === $tape->kindId($parent);
            $this->openSpanStart[$ordinal] = -1;
            $this->openClosed[$ordinal] = false;

            $tape->setFlags($ordinal, $fence['indent']);
            $tape->setEndOffset($ordinal, $state->lineEnd);

            return ContinueResult::Matched;
        }

        if (!$state->lineIsBlank && $this->isClosingFence($state, $this->openChar[$ordinal], $this->openLength[$ordinal])) {
            $this->openClosed[$ordinal] = true;

            return ContinueResult::Closed;
        }

        $contentEnd = $state->lineContentEnd;

        if ($this->openCompact[$ordinal]) {
            if (-1 === $this->openSpanStart[$ordinal]) {
                $this->openSpanStart[$ordinal] = $state->offset;
            }
        } else {
            // Content line: append its cursor-based range to the pair list
            // ("cs:ce;...|info"), so container markers never bleed into code.
            $pad = $state->takePendingPad();
            $offset = $state->offset;
            $column = $state->columnAt($offset);
            $pair = $offset . ':' . $contentEnd . ($pad > 0 || $column > 0 ? ':' . $pad . ':' . $column : '');
            $tape->appendPayloadPart($ordinal, $pair, ';');
        }

        $tape->setEndOffset($ordinal, $state->lineEnd);

        // Consume the whole line so the core loop's start phase stays inert
        // over code content.
        $state->advanceTo($contentEnd);

        return ContinueResult::Matched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        if (isset($this->openChar[$ordinal])) {
            $spanStart = $this->openSpanStart[$ordinal];

            if ($this->openCompact[$ordinal] && -1 !== $spanStart) {
                // The span end is the block's end offset: every content line
                // wrote it, and nothing between the last content line and this
                // close touches it. A final line with no EOL is exactly a span
                // end whose preceding byte is not an EOL byte, so the renderer
                // still learns it must append one.
                $spanEnd = $state->tape->endOffset($ordinal);
                $previous = $spanEnd > 0 ? $state->buffer->bytes[$spanEnd - 1] : "\n";
                $appendLf = "\n" !== $previous && "\r" !== $previous;

                $state->tape->setPayload(
                    $ordinal,
                    '@' . $spanStart . ':' . $spanEnd . ':' . ($appendLf ? '1' : '0')
                        . ($this->openClosed[$ordinal] ? '!' : '')
                        . '|' . $this->openInfo[$ordinal],
                );
            } else {
                $state->tape->appendPayloadPart(
                    $ordinal,
                    ($this->openClosed[$ordinal] ? '!' : '') . '|' . $this->openInfo[$ordinal],
                );
            }
        }

        unset(
            $this->openChar[$ordinal],
            $this->openLength[$ordinal],
            $this->openInfo[$ordinal],
            $this->openCompact[$ordinal],
            $this->openSpanStart[$ordinal],
            $this->openClosed[$ordinal],
        );
    }

    private function isClosingFence(ParserState $state, int $char, int $minLength): bool
    {
        $firstNonSpace = $state->firstNonSpaceFrom();
        $contentEnd = $state->lineContentEnd;
        $bytes = $state->buffer->bytes;

        // Reading the byte straight from the buffer instead of through the
        // guarded accessor: this test rejects every ordinary code line, so it
        // runs once per content line (PD.1). Past the content end the accessor
        // returned an EOL byte or -1, neither of which is a fence character.
        if ($firstNonSpace >= $contentEnd || $char !== \ord($bytes[$firstNonSpace])) {
            return false;
        }

        if ($state->cursorIndentFrom($firstNonSpace) >= 4) {
            return false;
        }

        $offset = $firstNonSpace;
        $fenceByte = \chr($char);

        while ($offset < $contentEnd && $fenceByte === $bytes[$offset]) {
            ++$offset;
        }

        if ($offset - $firstNonSpace < $minLength) {
            return false;
        }

        for (; $offset < $contentEnd; ++$offset) {
            $byte = $bytes[$offset];

            if (' ' !== $byte && "\t" !== $byte) {
                return false;
            }
        }

        return true;
    }
}
