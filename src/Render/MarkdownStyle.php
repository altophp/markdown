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

namespace Alto\Markdown\Render;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MarkdownStyle
{
    public function __construct(
        public string $bulletMarker = '-',
        public string $fenceMarker = '`',
        public bool $finalNewline = true,
        public bool $normalizeBlankLines = true,
        public bool $normalizeHeadingSpacing = true,
        public string $orderedListDelimiter = '.',
        public bool $normalizeTableDelimiters = true,
        public bool $normalizeReferenceDefinitionSpacing = true,
    ) {
        if (!\in_array($this->bulletMarker, ['-', '*', '+'], true)) {
            throw new InvalidMarkdownArgumentException(\sprintf('Invalid bullet marker "%s" (expected -, *, or +).', $this->bulletMarker));
        }

        if (!\in_array($this->fenceMarker, ['`', '~'], true)) {
            throw new InvalidMarkdownArgumentException(\sprintf('Invalid fence marker "%s" (expected ` or ~).', $this->fenceMarker));
        }

        if (!\in_array($this->orderedListDelimiter, ['.', ')'], true)) {
            throw new InvalidMarkdownArgumentException(\sprintf('Invalid ordered-list delimiter "%s" (expected . or )).', $this->orderedListDelimiter));
        }
    }

    public static function commonmark(): self
    {
        return new self();
    }

    public static function gfm(): self
    {
        return new self();
    }

    public static function github(): self
    {
        return new self();
    }
}
