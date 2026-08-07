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

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Parser\ParserState;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ExtensionBlockContext implements BlockStartContext, BlockContinueContext
{
    public function __construct(
        private ParserState $state,
        private bool $paragraphOpen,
        private BlockState $blockState = new BlockState(),
    ) {
    }

    public function lineStartOffset(): int
    {
        return $this->state->scanner()->contentStart($this->state->line);
    }

    public function lineContentEndOffset(): int
    {
        return $this->state->lineContentEnd;
    }

    public function firstNonSpaceOffset(): int
    {
        return $this->state->firstNonSpaceFrom();
    }

    public function indentColumns(): int
    {
        return $this->state->cursorIndentFrom($this->firstNonSpaceOffset());
    }

    public function byteAt(int $offset): int
    {
        $lineStart = $this->lineStartOffset();

        if ($offset < $lineStart || $offset >= $this->lineContentEndOffset()) {
            throw new InvalidExtensionException(\sprintf('Block extension byte offset %d must stay within current line content [%d, %d).', $offset, $lineStart, $this->lineContentEndOffset()));
        }

        return $this->state->buffer->byteAt($offset);
    }

    public function slice(int $startOffset, int $endOffset): string
    {
        $lineStart = $this->lineStartOffset();
        $lineEnd = $this->lineContentEndOffset();

        if ($startOffset < $lineStart || $startOffset > $endOffset || $endOffset > $lineEnd) {
            throw new InvalidExtensionException(\sprintf('Block extension slice %d..%d must stay within current line content [%d, %d].', $startOffset, $endOffset, $lineStart, $lineEnd));
        }

        return $this->state->buffer->substring($startOffset, $endOffset);
    }

    public function paragraphOpen(): bool
    {
        return $this->paragraphOpen;
    }

    public function state(): BlockState
    {
        return $this->blockState;
    }
}
