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
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\UnsupportedDocumentModelException;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SetCodeBlockLanguageOperation implements Operation
{
    public function __construct(
        private NodeId $target,
        private string $language,
        private SourceRange $range,
    ) {
        self::validateLanguage($language);
    }

    public static function validateLanguage(string $language): void
    {
        if ('' === $language || 1 === preg_match('/[\x00-\x20`]/', $language)) {
            throw new InvalidMarkdownArgumentException('Code block language must be a non-empty token without ASCII whitespace or backticks.');
        }
    }

    public function describe(): string
    {
        return 'set code block language';
    }

    public function apply(DocumentModel $model): void
    {
        $model = $this->parsed($model);
        $model->setCodeBlock($this->target, $this->language, $model->codeBlockCode($this->target->ordinal));
    }

    public function toPatch(DocumentModel $model): SourcePatch
    {
        $this->parsed($model);

        return new SourcePatch($this->range, $this->language, $this->range, $this->describe());
    }

    private function parsed(DocumentModel $model): ParsedDocumentModel
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new UnsupportedDocumentModelException('Code block operations require ParsedDocumentModel.');
        }

        return $model;
    }
}
