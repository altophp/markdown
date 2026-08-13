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

namespace Alto\Markdown\Extension\Tabs;

use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Document\PlannedHtmlBlockRenderer;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TabsGroupOutput implements PlannedHtmlBlockRenderer
{
    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        unset($context, $children);

        throw new \LogicException('Tabs output requires a document render plan.');
    }

    public function renderWithPlan(
        HtmlBlockOutputContext $context,
        string $children,
        DocumentRenderPlan $plan,
    ): string {
        $catalog = $plan->projection(TabsCatalog::class)
            ?? throw new \LogicException('Missing tabs projection.');
        $items = $catalog->group($context->range->startOffset);

        if ([] === $items) {
            return '';
        }

        $groupId = $catalog->groupId($context->range->startOffset);
        $html = '<div class="markdown-tabs" id="' . $context->escapeAttribute($groupId) . '">' . "\n";
        $html .= "<div class=\"markdown-tabs-list\">\n";

        foreach ($items as $item) {
            $class = 'markdown-tabs-tab' . (0 === $item['index'] ? ' is-active' : '');
            $html .= '<a class="' . $class . '" id="' . $context->escapeAttribute($item['tabId']) . '"'
                . ' href="#' . $context->escapeAttribute($item['panelId']) . '"'
                . ' aria-controls="' . $context->escapeAttribute($item['panelId']) . '">'
                . $context->escapeText($item['title'])
                . "</a>\n";
        }

        $html .= "</div>\n<div class=\"markdown-tabs-panels\">\n";
        $html .= $children;

        return $html . "</div>\n</div>\n";
    }
}
