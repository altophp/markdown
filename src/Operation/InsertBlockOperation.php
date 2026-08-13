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
final readonly class InsertBlockOperation implements Operation, InsertionPointOperation
{
    public function __construct(
        private NodeId $target,
        private string $markdown,
        private BlockInsertPosition $position,
        private SourceRange $range,
        private ?string $replacement = null,
        private bool $requiresFallback = false,
    ) {}

    public function describe(): string
    {
        return match ($this->position) {
            BlockInsertPosition::Before => 'insert block before target',
            BlockInsertPosition::After => 'insert block after target',
        };
    }

    public function apply(DocumentModel $model): void
    {
        $this->applyAndReturn($this->parsed($model));
    }

    /**
     * @return list<NodeId>
     */
    public function applyAndReturn(ParsedDocumentModel $model): array
    {
        return match ($this->position) {
            BlockInsertPosition::Before => $model->insertMarkdownBefore($this->target, $this->markdown),
            BlockInsertPosition::After => $model->insertMarkdownAfter($this->target, $this->markdown),
        };
    }

    public function toPatch(DocumentModel $model): ?SourcePatch
    {
        if ($this->requiresFallback) {
            return null;
        }

        return new SourcePatch($this->range, $this->replacement ?? $this->markdown, $this->range, $this->describe());
    }

    public function insertsAt(SourceRange $range): bool
    {
        return $this->range->startOffset === $range->startOffset
            && $this->range->endOffset === $range->endOffset;
    }

    private function parsed(DocumentModel $model): ParsedDocumentModel
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new UnsupportedDocumentModelException('Block operations require ParsedDocumentModel.');
        }

        return $model;
    }
}
