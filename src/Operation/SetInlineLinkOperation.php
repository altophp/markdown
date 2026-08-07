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
final readonly class SetInlineLinkOperation implements Operation
{
    public function __construct(
        private NodeId $block,
        private int $inlineOrdinal,
        private int $kind,
        private string $destination,
        private ?string $title,
        private ?string $imageAltText,
        private string $description,
        private ?SourceRange $range,
        private string $replacement,
    ) {
    }

    public function describe(): string
    {
        return $this->description;
    }

    public function apply(DocumentModel $model): void
    {
        $this->parsed($model)->setInlineLink(
            $this->block,
            $this->inlineOrdinal,
            $this->kind,
            $this->destination,
            $this->title,
            $this->imageAltText,
        );
    }

    public function toPatch(DocumentModel $model): ?SourcePatch
    {
        $this->parsed($model);

        return $this->range instanceof SourceRange
            ? new SourcePatch($this->range, $this->replacement, $this->range, $this->description)
            : null;
    }

    private function parsed(DocumentModel $model): ParsedDocumentModel
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new UnsupportedDocumentModelException('Inline link operations require ParsedDocumentModel.');
        }

        return $model;
    }
}
