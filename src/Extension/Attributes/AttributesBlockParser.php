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

namespace Alto\Markdown\Extension\Attributes;

use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Parser\Block\BlockConstruct;
use Alto\Markdown\Parser\Block\BlockStart;
use Alto\Markdown\Parser\Block\ContinueResult;
use Alto\Markdown\Parser\ParserState;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class AttributesBlockParser implements BlockConstruct
{
    public function __construct(
        private int $kind,
        private AttributeListParser $parser,
    ) {
    }

    public function kind(): int
    {
        return $this->kind;
    }

    public function triggerBytes(): string
    {
        return '{';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        unset($containerOrdinal, $paragraphOpen);

        $line = $this->line($state);
        if (null === $line || null === $this->parser->parseWhole($line)) {
            return null;
        }

        return new BlockStart(
            $this->kind,
            $state->lineContentEnd,
            isContainer: true,
            startOffset: $state->firstNonSpaceFrom(),
            extensionState: new BlockState([
                'source' => $line,
                'target' => 'undecided',
            ]),
        );
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $blockState = $state->tape->extensionBlockState($ordinal);
        $line = $this->line($state);

        if (null !== $line && null !== $this->parser->parseWhole($line)) {
            $state->advanceTo($state->lineContentEnd);
            $state->tape->setEndOffset($ordinal, $state->lineContentEnd);
            $state->tape->setExtensionBlockState(
                $ordinal,
                $blockState->with('source', $blockState->string('source')."\n".$line),
            );

            return ContinueResult::Matched;
        }

        $target = $state->lineIsBlank ? 'previous' : 'next';
        $state->tape->setExtensionBlockState($ordinal, $blockState->with('target', $target));

        return ContinueResult::NotMatched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        $blockState = $state->tape->extensionBlockState($ordinal);
        if ('undecided' === $blockState->string('target')) {
            $state->tape->setExtensionBlockState($ordinal, $blockState->with('target', 'previous'));
        }

        $state->tape->setEndOffset(
            $ordinal,
            max($state->tape->startOffset($ordinal), $state->tape->endOffset($ordinal)),
        );
    }

    private function line(ParserState $state): ?string
    {
        $first = $state->firstNonSpaceFrom();

        if ($first >= $state->lineContentEnd || $state->cursorIndentFrom($first) > 3) {
            return null;
        }

        return $state->buffer->substring($first, $state->lineContentEnd);
    }
}
