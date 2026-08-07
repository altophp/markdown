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

namespace Alto\Markdown\Extension\Include;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Parser\ParseOptions;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class IncludePolicy
{
    public function __construct(
        public int $maxDepth = 8,
        public int $maxResources = 64,
        public int $maxExpandedBytes = 1_048_576,
        public int $maxNestingDepth = 128,
        public int $maxBlockCount = 50_000,
        public int $maxInlineCount = 200_000,
        public int $maxReferenceCount = 10_000,
    ) {
        if ($maxDepth < 1 || $maxDepth > 64) {
            throw new InvalidExtensionException('Include depth limit must be between 1 and 64.');
        }
        if ($maxResources < 1 || $maxResources > 10_000) {
            throw new InvalidExtensionException('Include resource limit must be between 1 and 10000.');
        }
        if ($maxExpandedBytes < 1 || \PHP_INT_MAX === $maxExpandedBytes) {
            throw new InvalidExtensionException('Include byte limit must be between 1 and PHP_INT_MAX - 1.');
        }
        if ($maxNestingDepth < 1) {
            throw new InvalidExtensionException('Included Markdown nesting limit must be positive.');
        }
        if ($maxBlockCount < 1) {
            throw new InvalidExtensionException('Included Markdown block limit must be positive.');
        }
        if ($maxInlineCount < 1) {
            throw new InvalidExtensionException('Included Markdown inline limit must be positive.');
        }
        if ($maxReferenceCount < 1) {
            throw new InvalidExtensionException('Included Markdown reference limit must be positive.');
        }
    }

    public function parseOptions(): ParseOptions
    {
        return new ParseOptions(
            maxNestingDepth: $this->maxNestingDepth,
            maxSourceBytes: $this->maxExpandedBytes,
            maxBlockCount: $this->maxBlockCount,
            maxInlineCount: $this->maxInlineCount,
            maxReferenceCount: $this->maxReferenceCount,
        );
    }
}
