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

namespace Alto\Markdown\Render;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\RenderException;
use Alto\Markdown\Extension\Attributes\AttributesExtension;
use Alto\Markdown\Extension\Block\MarkdownBlockOutputContext;
use Alto\Markdown\Extension\DescriptionList\DescriptionListExtension;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Render\Block\BlockPrinter;
use Alto\Markdown\Render\Block\BlockQuotePrinter;
use Alto\Markdown\Render\Block\CodeBlockPrinter;
use Alto\Markdown\Render\Block\DescriptionListPrinter;
use Alto\Markdown\Render\Block\DescriptionPrinter;
use Alto\Markdown\Render\Block\DescriptionTermPrinter;
use Alto\Markdown\Render\Block\DocumentPrinter;
use Alto\Markdown\Render\Block\FrontMatterPrinter;
use Alto\Markdown\Render\Block\GitHubAlertPrinter;
use Alto\Markdown\Render\Block\HeadingPrinter;
use Alto\Markdown\Render\Block\HtmlBlockPrinter;
use Alto\Markdown\Render\Block\ListItemPrinter;
use Alto\Markdown\Render\Block\ListPrinter;
use Alto\Markdown\Render\Block\ParagraphPrinter;
use Alto\Markdown\Render\Block\TablePrinter;
use Alto\Markdown\Render\Block\ThematicBreakPrinter;

/**
 * Prints a parsed document back to Markdown.
 *
 * Shallow documents render through the recursive printers directly. Once
 * native recursion reaches {@see STACK_LIMIT} the walk hands off to an explicit
 * frame stack ({@see MarkdownBlockFrame}) so a deeply nested document (for
 * example 20,000 nested blockquotes) is bounded by heap, not by the native C
 * stack. Both paths drive the same block printers, so the output is identical.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class MarkdownRenderer implements Renderer
{
    /**
     * Native-recursion budget before the walk switches to the frame stack.
     */
    public const int STACK_LIMIT = 500;

    /**
     * @var array<string, BlockPrinter>
     */
    private readonly array $printers;

    public function __construct()
    {
        $heading = new HeadingPrinter();
        $code = new CodeBlockPrinter();

        $this->printers = [
            'document' => new DocumentPrinter(),
            'paragraph' => new ParagraphPrinter(),
            'atx-heading' => $heading,
            'setext-heading' => $heading,
            'thematic-break' => new ThematicBreakPrinter(),
            'fenced-code' => $code,
            'indented-code' => $code,
            'block-quote' => new BlockQuotePrinter(),
            'html-block' => new HtmlBlockPrinter(),
            'list' => new ListPrinter(),
            'list-item' => new ListItemPrinter(),
            GfmExtension::TABLE_KIND => new TablePrinter(),
            'github:alert' => new GitHubAlertPrinter(),
            'frontmatter:block' => new FrontMatterPrinter(),
            DescriptionListExtension::LIST_KIND => new DescriptionListPrinter(),
            DescriptionListExtension::TERM_KIND => new DescriptionTermPrinter(),
            DescriptionListExtension::DESCRIPTION_KIND => new DescriptionPrinter(),
        ];
    }

    public function render(DocumentModel $model, ?RenderOptions $options = null): string
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new RenderException('MarkdownRenderer requires a parsed document model.');
        }

        return $this->renderNode(
            $model,
            $model->rootNodeId()->ordinal,
            new RenderContext(
                $this,
                $options,
                $model->compiledProfile()->nodeKinds->find(AttributesExtension::BLOCK_KIND)?->id,
            ),
        );
    }

    public function renderBlock(ParsedDocumentModel $model, int $ordinal, ?RenderOptions $options = null): string
    {
        return $this->renderNode(
            $model,
            $ordinal,
            new RenderContext(
                $this,
                $options,
                $model->compiledProfile()->nodeKinds->find(AttributesExtension::BLOCK_KIND)?->id,
            ),
        );
    }

    public function renderNode(ParsedDocumentModel $model, int $ordinal, RenderContext $context): string
    {
        $context->enter();

        try {
            $kind = $model->renderNodeKindName($ordinal);

            if ($context->depth() >= self::STACK_LIMIT && $this->isContainer($model, $ordinal, $kind)) {
                return $this->renderDeepSubtree($model, $ordinal, $context, $kind);
            }

            return $this->printNode($model, $ordinal, $context, $kind);
        } finally {
            $context->leave();
        }
    }

    private function printNode(ParsedDocumentModel $model, int $ordinal, RenderContext $context, string $kind): string
    {
        $custom = $model->compiledProfile()->markdownBlockPrinters[$model->renderNodeKindId($ordinal)] ?? null;

        if (null !== $custom) {
            return $custom->print(
                new MarkdownBlockOutputContext(
                    $model->extensionBlockState($ordinal),
                    $model->range($model->currentNodeId($ordinal)),
                    $model->htmlSourceBytes(),
                    $context->style(),
                ),
                $context->renderChildren($model, $ordinal),
            );
        }

        $printer = $this->printers[$kind] ?? null;

        if (null === $printer) {
            throw new RenderException(\sprintf('No Markdown printer registered for node kind "%s".', $kind));
        }

        return $printer->print($model, $ordinal, $context);
    }

    /**
     * Expands one deep container block (and every descendant) with an explicit
     * frame stack once native recursion has reached {@see STACK_LIMIT}. Each
     * container's children are printed first and stashed on the context; the
     * matching printer then folds them into the parent without recursing.
     */
    private function renderDeepSubtree(ParsedDocumentModel $model, int $ordinal, RenderContext $context, string $kind): string
    {
        $stack = [$this->makeFrame($model, $ordinal, $kind)];
        $result = '';

        while (true) {
            $top = $stack[array_key_last($stack)];
            $cursor = $top->cursor;

            if (ParseTape::NONE === $cursor) {
                $string = $this->finishFrame($model, $context, $top);

                array_pop($stack);

                if ([] === $stack) {
                    $result = $string;

                    break;
                }

                $stack[array_key_last($stack)]->parts[] = $string;

                continue;
            }

            $top->cursor = $model->nextSiblingOrdinal($cursor);

            if (MarkdownBlockFrame::LIST === $top->kind) {
                $item = new MarkdownBlockFrame(MarkdownBlockFrame::ITEM, $cursor, $model->firstChildOrdinal($cursor));
                $item->marker = $top->ordered ? $top->number.'.' : $context->style()->bulletMarker;
                $item->loose = $top->loose;
                ++$top->number;
                $stack[] = $item;

                continue;
            }

            $childKind = $model->renderNodeKindName($cursor);

            if ($this->isContainer($model, $cursor, $childKind)) {
                $stack[] = $this->makeFrame($model, $cursor, $childKind);

                continue;
            }

            $top->parts[] = $this->printNode($model, $cursor, $context, $childKind);
        }

        return $result;
    }

    private function makeFrame(ParsedDocumentModel $model, int $ordinal, string $kind): MarkdownBlockFrame
    {
        switch ($kind) {
            case 'document':
                return new MarkdownBlockFrame(MarkdownBlockFrame::DOCUMENT, $ordinal, $model->firstChildOrdinal($ordinal));

            case 'block-quote':
                return new MarkdownBlockFrame(MarkdownBlockFrame::QUOTE, $ordinal, $model->firstChildOrdinal($ordinal));

            case 'github:alert':
                return new MarkdownBlockFrame(MarkdownBlockFrame::ALERT, $ordinal, $model->firstChildOrdinal($ordinal));

            case 'list':
                $frame = new MarkdownBlockFrame(MarkdownBlockFrame::LIST, $ordinal, $model->firstChildOrdinal($ordinal));
                $frame->ordered = $model->listIsOrdered($ordinal);
                $frame->loose = $model->listIsLoose($ordinal);
                $frame->number = $model->listStartNumber($ordinal);

                return $frame;

            default:
                return new MarkdownBlockFrame(MarkdownBlockFrame::PLAIN_ITEM, $ordinal, $model->firstChildOrdinal($ordinal));
        }
    }

    private function finishFrame(ParsedDocumentModel $model, RenderContext $context, MarkdownBlockFrame $frame): string
    {
        if (MarkdownBlockFrame::LIST === $frame->kind) {
            return implode($frame->loose ? "\n\n" : "\n", $frame->parts);
        }

        $context->stashChildren($frame->ordinal, $frame->parts);

        try {
            if (MarkdownBlockFrame::ITEM === $frame->kind) {
                return $context->renderListItem($model, $frame->ordinal, $frame->marker, $frame->loose);
            }

            return $this->printNode($model, $frame->ordinal, $context, $model->renderNodeKindName($frame->ordinal));
        } finally {
            $context->releaseChildren($frame->ordinal);
        }
    }

    private function isContainerKind(string $kind): bool
    {
        return 'document' === $kind
            || 'block-quote' === $kind
            || 'github:alert' === $kind
            || 'list' === $kind
            || 'list-item' === $kind
            || DescriptionListExtension::LIST_KIND === $kind
            || DescriptionListExtension::DESCRIPTION_KIND === $kind;
    }

    private function isContainer(ParsedDocumentModel $model, int $ordinal, string $kind): bool
    {
        return isset($model->compiledProfile()->markdownBlockPrinters[$model->renderNodeKindId($ordinal)])
            || $this->isContainerKind($kind);
    }
}
