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

namespace Alto\Markdown\Extension\DescriptionList;

use Alto\Markdown\Parser\Block\BlockConstruct;
use Alto\Markdown\Parser\Block\BlockStart;
use Alto\Markdown\Parser\Block\ContinueResult;
use Alto\Markdown\Parser\Block\ParagraphReplacementValidator;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DescriptionParser implements BlockConstruct, ParagraphReplacementValidator
{
    private const int TIGHT = 1;

    public function __construct(
        private int $listKind,
        private int $termKind,
        private int $kind,
    ) {
    }

    public function kind(): int
    {
        return $this->kind;
    }

    public function triggerBytes(): string
    {
        return ':';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        $marker = self::matchMarker($state);

        if (null === $marker) {
            return null;
        }

        if ($paragraphOpen) {
            return new BlockStart(
                $this->kind,
                $marker['contentOffset'],
                isContainer: true,
                replacesParagraph: true,
                flags: self::TIGHT | ($marker['contentColumn'] << 8),
                startOffset: $marker['startOffset'],
                paragraphWrapperKind: $this->listKind,
                paragraphChildKind: $this->termKind,
            );
        }

        if ($this->listKind !== $state->tape->kindId($containerOrdinal)) {
            return null;
        }

        return new BlockStart(
            $this->kind,
            $marker['contentOffset'],
            isContainer: true,
            flags: self::TIGHT | ($marker['contentColumn'] << 8),
            startOffset: $marker['startOffset'],
        );
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $tape = $state->tape;

        if ($state->lineIsBlank) {
            return ParseTape::NONE === $tape->firstChildOrdinal($ordinal)
                ? ContinueResult::NotMatched
                : ContinueResult::Matched;
        }

        $first = $state->firstNonSpaceFrom();
        $required = $tape->flags($ordinal) >> 8;
        $base = $state->baseColumn();

        if ($state->columnAt($first) - $base < $required) {
            return ContinueResult::NotMatched;
        }

        $state->advanceToColumn($base + $required);

        return ContinueResult::Matched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        $tape = $state->tape;
        $end = $tape->startOffset($ordinal);
        $child = $tape->firstChildOrdinal($ordinal);
        $previous = ParseTape::NONE;

        while (ParseTape::NONE !== $child) {
            if (ParseTape::NONE !== $previous
                && $state->hasBlankLineBetween($tape->endOffset($previous), $tape->startOffset($child))
            ) {
                $tape->setFlags($ordinal, $tape->flags($ordinal) & ~self::TIGHT);
            }

            $end = max($end, $tape->endOffset($child));
            $previous = $child;
            $child = $tape->nextSiblingOrdinal($child);
        }

        $tape->setEndOffset($ordinal, $end);
    }

    public function canReplaceParagraph(ParserState $state, ParseTape $tape, int $paragraph, BlockStart $start): bool
    {
        return null !== $tape->payload($paragraph);
    }

    /**
     * @return array{startOffset: int, contentOffset: int, contentColumn: int}|null
     */
    public static function matchMarker(ParserState $state): ?array
    {
        $first = $state->firstNonSpaceFrom();
        if ($first >= $state->lineContentEnd || $state->cursorIndentFrom($first) > 3) {
            return null;
        }

        $bytes = $state->buffer->bytes;
        if (':' !== $bytes[$first]) {
            return null;
        }

        $offset = $first + 1;
        if ($offset >= $state->lineContentEnd || (' ' !== $bytes[$offset] && "\t" !== $bytes[$offset])) {
            return null;
        }

        while ($offset < $state->lineContentEnd && (' ' === $bytes[$offset] || "\t" === $bytes[$offset])) {
            ++$offset;
        }

        return [
            'startOffset' => $first,
            'contentOffset' => $offset,
            'contentColumn' => $state->columnAt($offset) - $state->baseColumn(),
        ];
    }
}
