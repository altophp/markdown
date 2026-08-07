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

use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class DocumentEnsureRunner implements EnsureRunner
{
    /**
     * @var list<array{title: string, level: int|null}>
     */
    private array $sections = [];

    public function __construct(
        private readonly MarkdownDocument $document,
        private readonly ParsedDocumentModel $model,
    ) {
    }

    public function section(string $title, ?int $level = null): self
    {
        $this->sections[] = ['title' => $title, 'level' => $level];

        return $this;
    }

    public function apply(): MarkdownDocument
    {
        foreach ($this->sections as $section) {
            if ($this->document->section($section['title'])->exists()) {
                continue;
            }

            $level = $section['level'] ?? 1;
            $offset = \strlen($this->model->source()->bytes);
            $markdown = str_repeat('#', $level).' '.$section['title']."\n";
            $replacement = $this->appendSeparator($this->document->toMarkdown()).$markdown;
            $this->model->appendMarkdownToDocument($markdown);
            $this->model->journal()->record(
                new SourcePatchOperation(new SourcePatch(
                    new SourceRange($offset, $offset),
                    $replacement,
                    new SourceRange($offset, $offset),
                    \sprintf('ensure section "%s"', $section['title']),
                )),
                new SourceRange($offset, $offset),
            );
        }

        return $this->document;
    }

    private function appendSeparator(string $markdown): string
    {
        if ('' === $markdown || 1 === preg_match('/(?:\r\n|\r|\n){2}\z/', $markdown)) {
            return '';
        }

        if (1 === preg_match('/(?:\r\n|\r|\n)\z/', $markdown)) {
            return "\n";
        }

        return "\n\n";
    }
}
