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

namespace Alto\Markdown\Tests\Operation;

use Alto\Markdown\Document\InMemoryEditJournal;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\Operation;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class EditJournalTest extends TestCase
{
    public function testJournalRecordsOperationsInOrderWithAffectedRanges(): void
    {
        $first = new RecordingOperation('rename heading');
        $second = new RecordingOperation('replace body');
        $firstRange = new SourceRange(0, 8);
        $secondRange = new SourceRange(10, 22);
        $journal = new InMemoryEditJournal();

        $journal->record($first, $firstRange);
        $journal->record($second, $secondRange);

        self::assertFalse($journal->isEmpty());
        self::assertSame([$first, $second], $journal->operations());
        self::assertCount(2, $journal->entries());
        self::assertSame($first, $journal->entries()[0]->operation);
        self::assertSame($firstRange, $journal->entries()[0]->affectedRange);
        self::assertSame($second, $journal->entries()[1]->operation);
        self::assertSame($secondRange, $journal->entries()[1]->affectedRange);
    }

    public function testJournalClearResetsOperationsAndEntries(): void
    {
        $journal = new InMemoryEditJournal();
        $journal->record(new RecordingOperation('append section'), new SourceRange(12, 12));

        $journal->clear();

        self::assertTrue($journal->isEmpty());
        self::assertSame([], $journal->operations());
        self::assertSame([], $journal->entries());
    }

    public function testJournalEntryRangeIsOptional(): void
    {
        $operation = new RecordingOperation('operation without source range');
        $journal = new InMemoryEditJournal();

        $journal->record($operation);

        self::assertSame($operation, $journal->entries()[0]->operation);
        self::assertNull($journal->entries()[0]->affectedRange);
    }

    public function testSourcePatchCarriesPatchAndAffectedRangeMetadata(): void
    {
        $patchRange = new SourceRange(5, 9);
        $affectedRange = new SourceRange(0, 12);
        $patch = new SourcePatch($patchRange, 'replacement', $affectedRange, 'replace section body');

        self::assertSame($patchRange, $patch->range);
        self::assertSame('replacement', $patch->replacement);
        self::assertSame($affectedRange, $patch->affectedRange);
        self::assertSame('replace section body', $patch->description);
    }

    public function testSourcePatchDefaultsAffectedRangeToPatchRange(): void
    {
        $range = new SourceRange(5, 9);
        $patch = new SourcePatch($range, 'replacement');

        self::assertSame($range, $patch->affectedRange);
        self::assertSame('', $patch->description);
    }

    public function testPreviewDiffForEmptyJournalStaysEmptyAndDoesNotMutateJournal(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nBody.\n");

        self::assertTrue($document->model()->journal()->isEmpty());
        self::assertSame([], $document->model()->journal()->entries());

        $first = $document->diff();
        $second = $document->diff();

        self::assertTrue($first->isEmpty());
        self::assertSame('', $first->toUnifiedString());
        self::assertTrue($second->isEmpty());
        self::assertSame([], $document->model()->journal()->entries());
        self::assertTrue($document->model()->journal()->isEmpty());
    }
}

final readonly class RecordingOperation implements Operation
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
