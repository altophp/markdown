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
final readonly class ReplaceCodeBlockContentOperation implements Operation
{
    public function __construct(
        private NodeId $target,
        private ?string $language,
        private string $code,
        private SourceRange $range,
    ) {}

    public function describe(): string
    {
        return 'replace code block content';
    }

    public function apply(DocumentModel $model): void
    {
        $this->parsed($model)->setCodeBlock($this->target, $this->language, $this->code);
    }

    public function toPatch(DocumentModel $model): SourcePatch
    {
        return new SourcePatch($this->range, $this->fenced(), $this->range, $this->describe());
    }

    private function fenced(): string
    {
        $info = null === $this->language ? '' : $this->language;

        return "```{$info}\n" . rtrim($this->code, "\n") . "\n```\n";
    }

    private function parsed(DocumentModel $model): ParsedDocumentModel
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new UnsupportedDocumentModelException('Code block operations require ParsedDocumentModel.');
        }

        return $model;
    }
}
