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

namespace Alto\Markdown\Fixer;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Operation\Operation;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FixResult
{
    /**
     * @param list<Operation> $operations
     */
    public function __construct(
        public array $operations = [],
    ) {}

    public function isEmpty(): bool
    {
        return [] === $this->operations;
    }

    public function applyTo(DocumentModel $model): void
    {
        foreach ($this->operations as $operation) {
            $operation->apply($model);
            $patch = $operation->toPatch($model);
            $model->journal()->record($operation, $patch?->affectedRange);
        }
    }
}
