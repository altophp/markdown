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

/**
 * Transactional count for one inline block parse.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InlineCountBudgetSession
{
    private int $inlineCount = 0;

    private bool $committed = false;

    public function __construct(private readonly InlineCountBudget $budget) {}

    public function add(?int $byteOffset): void
    {
        $additionalCount = $this->inlineCount + 1;
        $this->budget->assertAdditional($additionalCount, $byteOffset);
        $this->inlineCount = $additionalCount;
    }

    public function commit(): void
    {
        if ($this->committed) {
            return;
        }

        $this->budget->commit($this->inlineCount);
        $this->committed = true;
    }
}
