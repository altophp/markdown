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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Extension\Inline\InlineParseContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ExtensionInlineContext implements InlineParseContext
{
    public function __construct(
        private InlineContent $content,
        private int $cursor,
    ) {
    }

    public function offset(): int
    {
        return $this->cursor;
    }

    public function length(): int
    {
        return \strlen($this->content->text);
    }

    public function remaining(): string
    {
        return substr($this->content->text, $this->cursor);
    }

    public function byteAt(int $offset): int
    {
        if ($offset < 0 || $offset >= $this->length()) {
            throw new InvalidExtensionException(\sprintf('Inline extension byte offset %d must stay within joined content [0, %d).', $offset, $this->length()));
        }

        return \ord($this->content->text[$offset]);
    }

    public function slice(int $startOffset, int $endOffset): string
    {
        $length = $this->length();

        if ($startOffset < 0 || $startOffset > $endOffset || $endOffset > $length) {
            throw new InvalidExtensionException(\sprintf('Inline extension slice %d..%d must stay within joined content [0, %d].', $startOffset, $endOffset, $length));
        }

        return substr($this->content->text, $startOffset, $endOffset - $startOffset);
    }
}
