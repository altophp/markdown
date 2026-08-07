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

namespace Alto\Markdown\Parser\Input;

use Alto\Markdown\Source\SourceRange;

/**
 * Immutable wrapper over the raw input bytes.
 *
 * Detects a leading UTF-8 BOM (EF BB BF) at offset 0 and records it. Offsets are
 * always original input bytes and include the BOM: when a BOM is present the
 * first content byte is at offset 3 (SPEC section 9). Slicing follows SourceRange
 * semantics: start inclusive, end exclusive.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SourceBuffer
{
    public int $length;

    public bool $hasBom;

    public function __construct(public string $bytes)
    {
        $this->length = \strlen($bytes);
        $this->hasBom = \str_starts_with($bytes, "\xEF\xBB\xBF");
    }

    /**
     * First content byte offset: 3 when a BOM is present, 0 otherwise.
     */
    public function contentStart(): int
    {
        return $this->hasBom ? 3 : 0;
    }

    /**
     * Byte value (0-255) at the given offset, or -1 when the offset is out of range.
     */
    public function byteAt(int $offset): int
    {
        if ($offset < 0 || $offset >= $this->length) {
            return -1;
        }

        return \ord($this->bytes[$offset]);
    }

    /**
     * Bytes in the range (start inclusive, end exclusive).
     */
    public function slice(SourceRange $range): string
    {
        return $this->substring($range->startOffset, $range->endOffset);
    }

    /**
     * Bytes from startOffset (inclusive) to endOffset (exclusive).
     */
    public function substring(int $startOffset, int $endOffset): string
    {
        $start = \max(0, $startOffset);

        if ($endOffset <= $start) {
            return '';
        }

        return \substr($this->bytes, $start, $endOffset - $start);
    }
}
