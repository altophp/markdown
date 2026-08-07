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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\UnsupportedDocumentModelException;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MoveBlockOperation implements MultiPatchOperation, InsertionPointOperation
{
    /**
     * @param list<SourcePatch> $patches
     */
    public function __construct(
        private NodeId $target,
        private NodeId $anchor,
        private BlockInsertPosition $position,
        private SourceRange $affectedRange,
        private array $patches = [],
        private ?SourceRange $insertionRange = null,
    ) {
    }

    public function describe(): string
    {
        return match ($this->position) {
            BlockInsertPosition::Before => 'move block before anchor',
            BlockInsertPosition::After => 'move block after anchor',
        };
    }

    public function apply(DocumentModel $model): void
    {
        $model = $this->parsed($model);

        match ($this->position) {
            BlockInsertPosition::Before => $model->moveBlockBefore($this->target, $this->anchor),
            BlockInsertPosition::After => $model->moveBlockAfter($this->target, $this->anchor),
        };
    }

    public function toPatch(DocumentModel $model): ?SourcePatch
    {
        return null;
    }

    public function affectedRange(): SourceRange
    {
        return $this->affectedRange;
    }

    public function toPatches(DocumentModel $model): array
    {
        return $this->patches;
    }

    public function insertsAt(SourceRange $range): bool
    {
        return $this->insertionRange instanceof SourceRange
            && $this->insertionRange->startOffset === $range->startOffset
            && $this->insertionRange->endOffset === $range->endOffset;
    }

    private function parsed(DocumentModel $model): ParsedDocumentModel
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new UnsupportedDocumentModelException('Block operations require ParsedDocumentModel.');
        }

        return $model;
    }
}
