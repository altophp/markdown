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

use Alto\Markdown\Parser\InlineCountBudgetSession;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;

/**
 * Inline tape emitter used only when a node budget is configured.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BudgetedInlineState extends InlineState
{
    public function __construct(
        private readonly InlineContent $budgetContent,
        ParseTape $tape,
        ReferenceMap $referenceMap,
        int $root,
        private readonly InlineCountBudgetSession $budget,
        private readonly bool $sourceOffsetsAreOriginal,
    ) {
        parent::__construct($budgetContent, $tape, $referenceMap, $root);
    }

    public function reserveNode(int $sourceOffset): void
    {
        $this->budget->add($this->sourceOffsetsAreOriginal ? $sourceOffset : null);
    }

    protected function append(int $kind, int $contentStart, int $contentEnd, int $flags = 0, ?string $payload = null): int
    {
        $sourceOffset = $this->budgetContent->sourceOffset($contentStart);
        $this->budget->add($this->sourceOffsetsAreOriginal ? $sourceOffset : null);

        return parent::append($kind, $contentStart, $contentEnd, $flags, $payload);
    }
}
