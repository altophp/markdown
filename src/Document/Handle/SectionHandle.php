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

namespace Alto\Markdown\Document\Handle;

use Alto\Markdown\Builder\MarkdownFragment;
use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Exception\StaleHandleException;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Node\Section;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class SectionHandle implements Section
{
    public function __construct(
        private readonly MarkdownDocument $document,
        private readonly ParsedDocumentModel $model,
        private NodeId $headingId,
        private int $endOffset,
    ) {
        ++Instrumentation::$sectionHandles;
    }

    public function id(): NodeId
    {
        return $this->headingId;
    }

    public function kind(): NodeKind
    {
        $this->assertExists();

        return new NodeKind(0, 'section');
    }

    public function range(): SourceRange
    {
        $this->assertExists();

        return new SourceRange($this->model->range($this->headingId)->startOffset, $this->endOffset);
    }

    public function title(): string
    {
        $this->assertExists();

        return $this->model->plainText($this->headingId->ordinal);
    }

    public function exists(): bool
    {
        return $this->model->exists($this->headingId);
    }

    public function rename(string $title): self
    {
        $this->assertExists();
        $this->headingId = new HeadingRename($this->model)->apply($this->headingId, $title, 'section');

        return $this;
    }

    public function remove(): MarkdownDocument
    {
        $this->assertExists();
        $range = $this->range();
        $title = $this->title();
        $this->model->removeSection($this->headingId, $this->endOffset);
        $this->record(\sprintf('remove section "%s"', $title), $range, '');

        return $this->document;
    }

    public function append(MarkdownFragment|string $content): self
    {
        $this->assertExists();
        $range = new SourceRange($this->endOffset, $this->endOffset);
        $markdown = $this->markdown($content);
        $this->model->appendMarkdownToSection($this->headingId, $this->endOffset, $markdown);
        $this->record(\sprintf('append to section "%s"', $this->title()), $range, $this->appendPatchMarkdown($markdown, $range));

        return $this;
    }

    public function prepend(MarkdownFragment|string $content): self
    {
        $this->assertExists();
        $headingRange = $this->model->range($this->headingId);
        $range = new SourceRange($headingRange->endOffset, $headingRange->endOffset);
        $markdown = $this->markdown($content);
        $this->model->prependMarkdownToSection($this->headingId, $this->endOffset, $markdown);
        $this->record(\sprintf('prepend to section "%s"', $this->title()), $range, $this->prependPatchMarkdown($markdown));

        return $this;
    }

    public function replaceBody(MarkdownFragment|string $content): MarkdownDocument
    {
        $this->assertExists();
        $headingRange = $this->model->range($this->headingId);
        $range = new SourceRange($headingRange->endOffset, $this->endOffset);
        $title = $this->title();
        $markdown = $this->markdown($content);
        $this->model->replaceSectionBody($this->headingId, $this->endOffset, $markdown);
        $this->record(\sprintf('replace body of section "%s"', $title), $range, $this->replaceBodyPatchMarkdown($markdown));

        return $this->document;
    }

    private function markdown(MarkdownFragment|string $content): string
    {
        return $content instanceof MarkdownFragment ? $content->toMarkdown() : $content;
    }

    private function record(string $description, SourceRange $range, string $replacement): void
    {
        $this->model->journal()->record(new SourcePatchOperation(new SourcePatch($range, $replacement, $range, $description)), $range);
    }

    private function appendPatchMarkdown(string $markdown, SourceRange $range): string
    {
        $prefix = $this->hasBlankBefore($range->startOffset) ? '' : "\n";

        return $prefix.rtrim($markdown, "\r\n")."\n\n";
    }

    private function prependPatchMarkdown(string $markdown): string
    {
        return "\n\n".rtrim($markdown, "\r\n");
    }

    private function replaceBodyPatchMarkdown(string $markdown): string
    {
        return "\n\n".rtrim($markdown, "\r\n")."\n\n";
    }

    private function hasBlankBefore(int $offset): bool
    {
        $prefix = \substr($this->model->source()->bytes, 0, $offset);

        return str_ends_with($prefix, "\n\n") || str_ends_with($prefix, "\r\n\r\n");
    }

    private function assertExists(): void
    {
        if (!$this->exists()) {
            throw new StaleHandleException('Section no longer exists.');
        }
    }
}
