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
final readonly class TabsItemParser implements BlockConstruct
{
    public function __construct(
        private int $groupKind,
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

        if ($this->groupKind !== $state->tape->kindId($containerOrdinal)) {
            return null;
        }

        $marker = $state->tape->extensionBlockState($containerOrdinal)->string('marker');
        $title = TabsSyntax::tabTitle($state, $marker);

        if (null === $title) {
            return null;
        }

        return new BlockStart(
            $this->kind,
            $state->lineContentEnd,
            isContainer: true,
            startOffset: $state->firstNonSpaceFrom(),
            extensionState: new BlockState([
                'marker' => $marker,
                'title' => $title,
            ]),
        );
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $marker = $state->tape->extensionBlockState($ordinal)->string('marker');

        return null === TabsSyntax::tabTitle($state, $marker)
            ? ContinueResult::Matched
            : ContinueResult::NotMatched;
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
}
