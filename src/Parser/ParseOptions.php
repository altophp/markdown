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

namespace Alto\Markdown\Parser;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;

/**
 * Parse-time settings.
 *
 * Resource limits use zero for an explicit unbounded value. Source offsets and
 * inline spans remain mandatory because the parse tape stores content through
 * original-input byte ranges.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ParseOptions
{
    /**
     * Real documents nest a handful of levels deep. This default sits two
     * orders of magnitude above observed content, so it refuses hostile input
     * without reaching any document a human wrote.
     */
    public const int DEFAULT_MAX_NESTING_DEPTH = 256;

    public const int UNBOUNDED_NESTING_DEPTH = 0;

    public const int UNBOUNDED_SOURCE_BYTES = 0;

    public const int UNBOUNDED_BLOCK_COUNT = 0;

    public const int UNBOUNDED_INLINE_COUNT = 0;

    public const int UNBOUNDED_REFERENCE_COUNT = 0;

    /**
     * Maximum number of blocks open at once below the document root. Parsing
     * throws NestingLimitException when input would open one more.
     * UNBOUNDED_NESTING_DEPTH disables the limit.
     */
    public function __construct(
        public int $maxNestingDepth = self::DEFAULT_MAX_NESTING_DEPTH,
        public int $maxSourceBytes = self::UNBOUNDED_SOURCE_BYTES,
        public int $maxBlockCount = self::UNBOUNDED_BLOCK_COUNT,
        public int $maxInlineCount = self::UNBOUNDED_INLINE_COUNT,
        public int $maxReferenceCount = self::UNBOUNDED_REFERENCE_COUNT,
    ) {
        if ($maxNestingDepth < 0) {
            throw new InvalidMarkdownArgumentException('Maximum nesting depth must be zero or greater.');
        }

        if ($maxSourceBytes < 0) {
            throw new InvalidMarkdownArgumentException('Maximum source bytes must be zero or greater.');
        }

        if ($maxBlockCount < 0) {
            throw new InvalidMarkdownArgumentException('Maximum block count must be zero or greater.');
        }

        if ($maxInlineCount < 0) {
            throw new InvalidMarkdownArgumentException('Maximum inline count must be zero or greater.');
        }

        if ($maxReferenceCount < 0) {
            throw new InvalidMarkdownArgumentException('Maximum reference count must be zero or greater.');
        }
    }

    public function withMaxNestingDepth(int $depth): self
    {
        return new self($depth, $this->maxSourceBytes, $this->maxBlockCount, $this->maxInlineCount, $this->maxReferenceCount);
    }

    public function withUnboundedNestingDepth(): self
    {
        return $this->withMaxNestingDepth(self::UNBOUNDED_NESTING_DEPTH);
    }

    public function withMaxSourceBytes(int $bytes): self
    {
        return new self($this->maxNestingDepth, $bytes, $this->maxBlockCount, $this->maxInlineCount, $this->maxReferenceCount);
    }

    public function withUnboundedSourceBytes(): self
    {
        return $this->withMaxSourceBytes(self::UNBOUNDED_SOURCE_BYTES);
    }

    public function withMaxBlockCount(int $count): self
    {
        return new self($this->maxNestingDepth, $this->maxSourceBytes, $count, $this->maxInlineCount, $this->maxReferenceCount);
    }

    public function withUnboundedBlockCount(): self
    {
        return $this->withMaxBlockCount(self::UNBOUNDED_BLOCK_COUNT);
    }

    public function withMaxInlineCount(int $count): self
    {
        return new self($this->maxNestingDepth, $this->maxSourceBytes, $this->maxBlockCount, $count, $this->maxReferenceCount);
    }

    public function withUnboundedInlineCount(): self
    {
        return $this->withMaxInlineCount(self::UNBOUNDED_INLINE_COUNT);
    }

    public function withMaxReferenceCount(int $count): self
    {
        return new self($this->maxNestingDepth, $this->maxSourceBytes, $this->maxBlockCount, $this->maxInlineCount, $count);
    }

    public function withUnboundedReferenceCount(): self
    {
        return $this->withMaxReferenceCount(self::UNBOUNDED_REFERENCE_COUNT);
    }
}
