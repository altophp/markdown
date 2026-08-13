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
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DescriptionListParser implements BlockConstruct
{
    public function __construct(
        private int $kind,
        private int $descriptionKind,
    ) {}

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
        return null;
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        if ($state->lineIsBlank || null !== DescriptionParser::matchMarker($state)) {
            return ContinueResult::Matched;
        }

        $last = $this->lastChild($state, $ordinal);
        if (ParseTape::NONE === $last
            || $this->descriptionKind !== $state->tape->kindId($last)
            || ParseTape::NONE !== $state->tape->endOffset($last)
        ) {
            return ContinueResult::NotMatched;
        }

        $first = $state->firstNonSpaceFrom();

        return $state->columnAt($first) - $state->baseColumn() >= ($state->tape->flags($last) >> 8)
            ? ContinueResult::Matched
            : ContinueResult::NotMatched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        $end = $state->tape->startOffset($ordinal);
        $child = $state->tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $end = max($end, $state->tape->endOffset($child));
            $child = $state->tape->nextSiblingOrdinal($child);
        }

        $state->tape->setEndOffset($ordinal, $end);
    }

    private function lastChild(ParserState $state, int $ordinal): int
    {
        $last = ParseTape::NONE;
        $child = $state->tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $last = $child;
            $child = $state->tape->nextSiblingOrdinal($child);
        }

        return $last;
    }
}
