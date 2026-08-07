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

use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface EditJournal
{
    /**
     * @return list<Operation>
     */
    public function operations(): array;

    /**
     * @return list<EditJournalEntry>
     */
    public function entries(): array;

    public function isEmpty(): bool;

    public function record(Operation $operation, ?SourceRange $affectedRange = null): void;

    public function clear(): void;
}
