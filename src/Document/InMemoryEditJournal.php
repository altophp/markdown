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

namespace Alto\Markdown\Document;

use Alto\Markdown\Operation\EditJournal;
use Alto\Markdown\Operation\EditJournalEntry;
use Alto\Markdown\Operation\Operation;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InMemoryEditJournal implements EditJournal
{
    /**
     * @var list<EditJournalEntry>
     */
    private array $entries = [];

    public function __construct()
    {
        ++Instrumentation::$editJournals;
    }

    public function operations(): array
    {
        return array_map(static fn (EditJournalEntry $entry): Operation => $entry->operation, $this->entries);
    }

    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    public function record(Operation $operation, ?SourceRange $affectedRange = null): void
    {
        $this->entries[] = new EditJournalEntry($operation, $affectedRange);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
