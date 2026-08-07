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
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ReplaceFrontMatterContentOperation implements Operation
{
    public function __construct(
        private NodeId $target,
        private string $content,
        private SourceRange $range,
    ) {
    }

    public function describe(): string
    {
        return 'replace front matter content';
    }

    public function apply(DocumentModel $model): void
    {
        $this->parsed($model)->setFrontMatterContent($this->target, $this->content);
    }

    public function toPatch(DocumentModel $model): SourcePatch
    {
        $this->parsed($model);

        return new SourcePatch($this->range, $this->content, $this->range, $this->describe());
    }

    private function parsed(DocumentModel $model): ParsedDocumentModel
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new UnsupportedDocumentModelException('Front matter operations require ParsedDocumentModel.');
        }

        return $model;
    }
}
