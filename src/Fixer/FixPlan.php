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
use Alto\Markdown\Exception\PatchConflictException;
use Alto\Markdown\Operation\Operation;
use Alto\Markdown\Operation\SourcePatch;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FixPlan
{
    /**
     * @param list<Operation> $operations
     */
    public function __construct(
        private array $operations = [],
    ) {
    }

    /**
     * @param iterable<FixResult> $results
     */
    public static function fromResults(iterable $results): self
    {
        $operations = [];

        foreach ($results as $result) {
            if ($result->isEmpty()) {
                continue;
            }

            array_push($operations, ...$result->operations);
        }

        return new self($operations);
    }

    public function isEmpty(): bool
    {
        return [] === $this->operations;
    }

    /**
     * @return list<Operation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function applyTo(DocumentModel $model): void
    {
        $operations = $this->pendingOperations($model);

        $this->assertNoSourcePatchCollisions($model, $operations);

        new FixResult($operations)->applyTo($model);
        $this->canonicalizeComposableEofInsertions($model);
    }

    /**
     * @return list<Operation>
     */
    private function pendingOperations(DocumentModel $model): array
    {
        $existing = [];
        $pending = [];

        foreach ($model->journal()->operations() as $operation) {
            $patch = $operation->toPatch($model);

            if ($patch instanceof SourcePatch) {
                $existing[] = $patch;
            }
        }

        foreach ($this->operations as $operation) {
            $patch = $operation->toPatch($model);

            if (!$patch instanceof SourcePatch) {
                $pending[] = $operation;

                continue;
            }

            $alreadyJournaled = false;
            foreach ($existing as $existingPatch) {
                if ($this->samePatch($existingPatch, $patch)) {
                    $alreadyJournaled = true;

                    break;
                }
            }

            if ($alreadyJournaled) {
                continue;
            }

            foreach ($existing as $existingPatch) {
                if ($this->collides($existingPatch, $patch) && !$this->isComposableEofInsertion($model, $existingPatch, $patch)) {
                    throw new PatchConflictException(\sprintf('Fix operation "%s" overlaps an already journaled source patch at [%d, %d) and [%d, %d).', $operation->describe(), $existingPatch->range->startOffset, $existingPatch->range->endOffset, $patch->range->startOffset, $patch->range->endOffset));
                }
            }

            $pending[] = $operation;
        }

        return $pending;
    }

    /**
     * @param list<Operation> $operations
     */
    private function assertNoSourcePatchCollisions(DocumentModel $model, array $operations): void
    {
        $patches = [];

        foreach ($operations as $index => $operation) {
            $patch = $operation->toPatch($model);

            if (!$patch instanceof SourcePatch) {
                continue;
            }

            $patches[] = [$patch, $operation, $index];
        }

        usort(
            $patches,
            static fn (array $left, array $right): int => [
                $left[0]->range->startOffset,
                $left[0]->range->endOffset,
                $left[2],
            ] <=> [
                $right[0]->range->startOffset,
                $right[0]->range->endOffset,
                $right[2],
            ],
        );

        $previous = null;

        foreach ($patches as $entry) {
            if (null !== $previous && $this->collides($previous[0], $entry[0]) && !$this->isComposableEofInsertion($model, $previous[0], $entry[0])) {
                throw new PatchConflictException(\sprintf('Overlapping fix operations "%s" and "%s" at [%d, %d) and [%d, %d).', $previous[1]->describe(), $entry[1]->describe(), $previous[0]->range->startOffset, $previous[0]->range->endOffset, $entry[0]->range->startOffset, $entry[0]->range->endOffset));
            }

            $previous = $entry;
        }
    }

    private function collides(SourcePatch $previous, SourcePatch $patch): bool
    {
        if ($patch->range->startOffset < $previous->range->endOffset) {
            return true;
        }

        return $patch->range->startOffset === $previous->range->startOffset
            && $patch->range->endOffset === $patch->range->startOffset
            && $previous->range->endOffset === $previous->range->startOffset;
    }

    private function samePatch(SourcePatch $left, SourcePatch $right): bool
    {
        return $left->range->startOffset === $right->range->startOffset
            && $left->range->endOffset === $right->range->endOffset
            && $left->replacement === $right->replacement;
    }

    private function isComposableEofInsertion(DocumentModel $model, SourcePatch $previous, SourcePatch $patch): bool
    {
        $sourceLength = \strlen($model->source()->bytes);

        return $previous->range->startOffset === $sourceLength
            && $previous->range->endOffset === $sourceLength
            && $patch->range->startOffset === $sourceLength
            && $patch->range->endOffset === $sourceLength
            && ($this->isContentThenNewline($model, $previous, $patch) || $this->isContentThenNewline($model, $patch, $previous));
    }

    private function isContentThenNewline(DocumentModel $model, SourcePatch $content, SourcePatch $newline): bool
    {
        return '' !== $content->replacement
            && !str_ends_with($content->replacement, "\n")
            && !str_ends_with($content->replacement, "\r")
            && $model->source()->dominantEol->value === $newline->replacement;
    }

    private function canonicalizeComposableEofInsertions(DocumentModel $model): void
    {
        $journal = $model->journal();
        $entries = $journal->entries();
        $contentIndex = null;
        $newlineIndex = null;

        foreach ($entries as $index => $entry) {
            $patch = $entry->operation->toPatch($model);

            if (!$patch instanceof SourcePatch || !$this->isEofInsertion($model, $patch)) {
                continue;
            }

            if ($model->source()->dominantEol->value === $patch->replacement) {
                $newlineIndex = $index;
            } elseif ('' !== $patch->replacement && !str_ends_with($patch->replacement, "\n") && !str_ends_with($patch->replacement, "\r")) {
                $contentIndex = $index;
            }
        }

        if (null === $contentIndex || null === $newlineIndex || $contentIndex < $newlineIndex) {
            return;
        }

        $newlineEntry = $entries[$newlineIndex];
        array_splice($entries, $newlineIndex, 1);
        array_splice($entries, $contentIndex, 0, [$newlineEntry]);

        $journal->clear();

        foreach ($entries as $entry) {
            $journal->record($entry->operation, $entry->affectedRange);
        }
    }

    private function isEofInsertion(DocumentModel $model, SourcePatch $patch): bool
    {
        $sourceLength = \strlen($model->source()->bytes);

        return $patch->range->startOffset === $sourceLength && $patch->range->endOffset === $sourceLength;
    }
}
