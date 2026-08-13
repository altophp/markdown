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

namespace Alto\Markdown\Extension\TableOfContents;

use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\MarkdownBlockOutputContext;
use Alto\Markdown\Extension\Block\MarkdownBlockPrinter;
use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Document\PlannedHtmlBlockRenderer;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TableOfContentsOutput implements PlannedHtmlBlockRenderer, MarkdownBlockPrinter
{
    public function __construct(private TableOfContentsPolicy $policy) {}

    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        unset($context, $children);

        throw new \LogicException('Table of contents output requires a document render plan.');
    }

    public function renderWithPlan(
        HtmlBlockOutputContext $context,
        string $children,
        DocumentRenderPlan $plan,
    ): string {
        unset($children);

        $catalog = $plan->projection(TableOfContentsCatalog::class)
            ?? throw new \LogicException('Missing table of contents projection.');
        $min = $context->state()->int('min');
        $max = $context->state()->int('max');
        $headings = array_values(array_filter(
            $catalog->headings,
            static fn(TableOfContentsHeading $heading): bool => $heading->level >= $min
                && $heading->level <= $max,
        ));

        if ([] === $headings) {
            return '';
        }

        $tag = true === $context->state()->value('ordered') ? 'ol' : 'ul';
        $attributes = [];

        if ('' !== $this->policy->htmlClass) {
            $attributes[] = 'class="' . $context->escapeAttribute($this->policy->htmlClass) . '"';
        }

        if ('' !== $this->policy->id) {
            $index = $catalog->markerIndex($context->range->startOffset);
            $id = 0 === $index ? $this->policy->id : $this->policy->id . '-' . $index;
            $attributes[] = 'id="' . $context->escapeAttribute($id) . '"';
        }

        $html = '<nav' . ([] === $attributes ? '' : ' ' . implode(' ', $attributes)) . ">\n";

        if (null !== $this->policy->title) {
            $html .= '<p class="table-of-contents-title">'
                . $context->escapeText($this->policy->title)
                . "</p>\n";
        }

        $html .= $this->renderItems($this->tree($headings), $tag, $context);

        return $html . "</nav>\n";
    }

    public function print(MarkdownBlockOutputContext $context, string $children): string
    {
        unset($children);

        return $context->source();
    }

    /**
     * @param list<TableOfContentsHeading> $headings
     *
     * @return list<TableOfContentsTreeItem>
     */
    private function tree(array $headings): array
    {
        $roots = [];

        /**
         * @var list<TableOfContentsTreeItem>
         */
        $stack = [];

        foreach ($headings as $heading) {
            while ([] !== $stack && $stack[array_key_last($stack)]->heading->level >= $heading->level) {
                array_pop($stack);
            }

            $item = new TableOfContentsTreeItem($heading);

            if ([] === $stack) {
                $roots[] = $item;
            } else {
                $stack[array_key_last($stack)]->children[] = $item;
            }

            $stack[] = $item;
        }

        return $roots;
    }

    /**
     * @param list<TableOfContentsTreeItem> $items
     */
    private function renderItems(array $items, string $tag, HtmlBlockOutputContext $context): string
    {
        $html = '<' . $tag . ">\n";

        foreach ($items as $item) {
            $html .= '<li><a href="#'
                . $context->escapeAttribute(TableOfContentsCatalog::targetId($item->heading->slug))
                . '">'
                . $context->escapeText($item->heading->text)
                . '</a>';

            if ([] !== $item->children) {
                $html .= "\n" . $this->renderItems($item->children, $tag, $context);
            }

            $html .= "</li>\n";
        }

        return $html . '</' . $tag . ">\n";
    }
}
