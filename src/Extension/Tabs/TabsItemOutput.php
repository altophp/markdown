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
final readonly class TabsItemOutput implements PlannedHtmlBlockRenderer
{
    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        unset($context, $children);

        throw new \LogicException('Tab item output requires a document render plan.');
    }

    public function renderWithPlan(
        HtmlBlockOutputContext $context,
        string $children,
        DocumentRenderPlan $plan,
    ): string {
        $catalog = $plan->projection(TabsCatalog::class)
            ?? throw new \LogicException('Missing tabs projection.');
        $item = $catalog->item($context->range->startOffset);
        $catalog->recordTitle(
            $context->range->startOffset,
            $context->state()->string('title'),
        );
        $class = 'markdown-tabs-panel' . (0 === $item['index'] ? ' is-active' : '');
        $html = '<div class="' . $class . '" id="' . $context->escapeAttribute($item['panelId']) . '"'
            . ' aria-labelledby="' . $context->escapeAttribute($item['tabId']) . '">';

        if ('' !== $children) {
            $html .= "\n" . $children;
        }

        return $html . "</div>\n";
    }
}
