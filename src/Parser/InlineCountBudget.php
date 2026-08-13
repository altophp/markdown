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

use Alto\Markdown\Exception\InlineCountLimitException;

/**
 * Document-wide count of committed inline allocations.
 *
 * Each block parse uses a session so a failed or abandoned parse does not
 * consume the document budget.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InlineCountBudget
{
    private int $inlineCount = 0;

    public function __construct(private readonly int $maxInlineCount) {}

    public function begin(): InlineCountBudgetSession
    {
        return new InlineCountBudgetSession($this);
    }

    public function assertAdditional(int $additionalCount, ?int $byteOffset): void
    {
        $attemptedCount = $this->inlineCount + $additionalCount;

        if ($attemptedCount > $this->maxInlineCount) {
            throw new InlineCountLimitException($this->maxInlineCount, $attemptedCount, $byteOffset);
        }
    }

    public function commit(int $additionalCount): void
    {
        $this->inlineCount += $additionalCount;
    }
}
