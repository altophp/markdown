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

use Alto\Markdown\Exception\InvalidBlockResultException;
use Alto\Markdown\Extension\Block\BlockContinueAction;
use Alto\Markdown\Extension\Block\BlockParser as ExtensionBlockParser;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class PublicBlockParserAdapter implements BlockConstruct
{
    public function __construct(
        private int $kind,
        private ExtensionBlockParser $parser,
    ) {}

    public function kind(): int
    {
        return $this->kind;
    }

    public function triggerBytes(): string
    {
        return $this->parser->triggerBytes();
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        if (
            $this->parser instanceof RootOnlyBlockParser
            && ParseTape::NONE !== $state->tape->parentOrdinal($containerOrdinal)
        ) {
            return null;
        }

        $result = $this->parser->tryStart(new ExtensionBlockContext($state, $paragraphOpen));

        if (null === $result) {
            return null;
        }

        $this->validateRange($state, $result->startOffset, $result->advanceOffset);

        return new BlockStart(
            $this->kind,
            $result->advanceOffset,
            isContainer: $result->container,
            startOffset: $result->startOffset,
            extensionState: $result->state,
        );
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $blockState = $state->tape->extensionBlockState($ordinal);
        $result = $this->parser->tryContinue(new ExtensionBlockContext($state, false, $blockState));

        if (null !== $result->advanceOffset) {
            $this->validateAdvance($state, $result->advanceOffset);
            $state->advanceTo($result->advanceOffset);
        }

        if (null !== $result->state) {
            $state->tape->setExtensionBlockState($ordinal, $result->state);
        }

        if (BlockContinueAction::Closed === $result->action) {
            if (null === $result->advanceOffset) {
                throw new InvalidBlockResultException('A closed custom block must provide an advance offset.');
            }

            $state->tape->setEndOffset($ordinal, $result->advanceOffset);
        } elseif (BlockContinueAction::Matched === $result->action) {
            $state->tape->setEndOffset($ordinal, $state->lineContentEnd);
        }

        return match ($result->action) {
            BlockContinueAction::Matched => ContinueResult::Matched,
            BlockContinueAction::NotMatched => ContinueResult::NotMatched,
            BlockContinueAction::Closed => ContinueResult::Closed,
        };
    }

    public function close(ParserState $state, int $ordinal): void
    {
        $endOffset = max($state->tape->startOffset($ordinal), $state->tape->endOffset($ordinal));
        $child = $state->tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $endOffset = max($endOffset, $state->tape->endOffset($child));
            $child = $state->tape->nextSiblingOrdinal($child);
        }

        $state->tape->setEndOffset($ordinal, $endOffset);
    }

    private function validateRange(ParserState $state, int $startOffset, int $advanceOffset): void
    {
        if ($startOffset < $state->offset || $startOffset > $advanceOffset || $advanceOffset > $state->lineContentEnd) {
            throw new InvalidBlockResultException(\sprintf('Custom block range %d..%d must stay between cursor %d and line content end %d.', $startOffset, $advanceOffset, $state->offset, $state->lineContentEnd));
        }
    }

    private function validateAdvance(ParserState $state, int $advanceOffset): void
    {
        if ($advanceOffset < $state->offset || $advanceOffset > $state->lineContentEnd) {
            throw new InvalidBlockResultException(\sprintf('Custom block advance offset %d must stay between cursor %d and line content end %d.', $advanceOffset, $state->offset, $state->lineContentEnd));
        }
    }
}
