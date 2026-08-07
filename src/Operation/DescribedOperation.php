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

namespace Alto\Markdown\Operation;

use Alto\Markdown\DocumentModel;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DescribedOperation implements Operation
{
    public function __construct(private string $description)
    {
    }

    public function describe(): string
    {
        return $this->description;
    }

    public function apply(DocumentModel $model): void
    {
    }

    public function toPatch(DocumentModel $model): ?SourcePatch
    {
        return null;
    }
}
