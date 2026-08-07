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

namespace Alto\Markdown\Extension\Footnote;

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
final readonly class FootnoteDefinitionOutput implements PlannedHtmlBlockRenderer, MarkdownBlockPrinter
{
    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        unset($context, $children);

        throw new \LogicException('Footnote output requires a document render plan.');
    }

    public function renderWithPlan(
        HtmlBlockOutputContext $context,
        string $children,
        DocumentRenderPlan $plan,
    ): string {
        $catalog = $plan->projection(FootnoteCatalog::class)
            ?? throw new \LogicException('Missing footnote projection.');
        $catalog->recordHtml($context->state()->string('label'), $children);

        return '';
    }

    public function print(MarkdownBlockOutputContext $context, string $children): string
    {
        unset($children);

        return $context->source();
    }
}
