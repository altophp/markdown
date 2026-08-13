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

namespace Alto\Markdown\Extension\Tabs;

use Alto\Markdown\Extension\Block\BlockState;
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
final readonly class TabsGroupParser implements BlockConstruct
{
    public function __construct(
        private int $kind,
    ) {}

    public function kind(): int
    {
        return $this->kind;
    }

    public function triggerBytes(): string
    {
        return '@';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        unset($paragraphOpen);

        $marker = TabsSyntax::groupMarker($state);
        if (null === $marker) {
            return null;
        }

        $parentMarker = $this->ancestorMarker($state, $containerOrdinal);
        $expectedLength = null === $parentMarker ? 1 : \strlen($parentMarker) + 1;

        if (\strlen($marker) !== $expectedLength) {
            return null;
        }

        $first = $state->firstNonSpaceFrom();

        return new BlockStart(
            $this->kind,
            $state->lineContentEnd,
            isContainer: true,
            startOffset: $first,
            extensionState: new BlockState(['marker' => $marker]),
        );
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $marker = $state->tape->extensionBlockState($ordinal)->string('marker');

        if (TabsSyntax::closesGroup($state, $marker)) {
            $state->advanceTo($state->lineContentEnd);
            $state->tape->setEndOffset($ordinal, $state->lineContentEnd);

            return ContinueResult::Closed;
        }

        if (ParseTape::NONE === $state->tape->firstChildOrdinal($ordinal)
            && null === TabsSyntax::tabTitle($state, $marker)
        ) {
            $state->advanceTo($state->lineContentEnd);
        }

        return ContinueResult::Matched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        $endOffset = max(
            $state->tape->startOffset($ordinal),
            $state->tape->endOffset($ordinal),
        );
        $child = $state->tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $endOffset = max($endOffset, $state->tape->endOffset($child));
            $child = $state->tape->nextSiblingOrdinal($child);
        }

        $state->tape->setEndOffset($ordinal, $endOffset);
    }

    private function ancestorMarker(ParserState $state, int $ordinal): ?string
    {
        while (ParseTape::NONE !== $ordinal) {
            if ($this->kind === $state->tape->kindId($ordinal)) {
                return $state->tape->extensionBlockState($ordinal)->string('marker');
            }

            $ordinal = $state->tape->parentOrdinal($ordinal);
        }

        return null;
    }
}
