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
final readonly class ReplaceBlockOperation implements Operation
{
    public function __construct(
        private NodeId $target,
        private string $markdown,
        private SourceRange $range,
        private string $replacement,
        private string $description = 'replace block',
        private bool $requiresFallback = false,
    ) {}

    public function describe(): string
    {
        return $this->description;
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
        return $model->replaceBlockWithMarkdown($this->target, $this->markdown);
    }

    public function toPatch(DocumentModel $model): ?SourcePatch
    {
        return $this->requiresFallback
            ? null
            : new SourcePatch($this->range, $this->replacement, $this->range, $this->description);
    }

    private function parsed(DocumentModel $model): ParsedDocumentModel
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new UnsupportedDocumentModelException('Block operations require ParsedDocumentModel.');
        }

        return $model;
    }
}
