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

use Alto\Markdown\Exception\BlockCountLimitException;

/**
 * Counts semantic block nodes below the document root.
 *
 * Paragraph extraction replaces one semantic node with one or more reference
 * definitions and an optional surviving paragraph. Setext conversion changes
 * a paragraph's kind without changing this count.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BlockCountBudget
{
    private int $blockCount = 0;

    public function __construct(private readonly int $maxBlockCount) {}

    public function add(int $byteOffset): void
    {
        $this->replace(0, 1, $byteOffset);
    }

    public function replaceOneWith(int $replacementCount, int $byteOffset): void
    {
        $this->replace(1, $replacementCount, $byteOffset);
    }

    private function replace(int $removedCount, int $addedCount, int $byteOffset): void
    {
        $attemptedCount = $this->blockCount - $removedCount + $addedCount;

        if ($attemptedCount > $this->maxBlockCount) {
            throw new BlockCountLimitException($this->maxBlockCount, $attemptedCount, $byteOffset);
        }

        $this->blockCount = $attemptedCount;
    }
}
