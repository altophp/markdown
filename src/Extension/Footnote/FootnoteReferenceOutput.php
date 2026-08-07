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

use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Document\PlannedHtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\LinkLikeInlineRenderer;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FootnoteReferenceOutput implements PlannedHtmlInlineRenderer, LinkLikeInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        return $context->escapeText($context->source());
    }

    public function renderWithPlan(HtmlInlineOutputContext $context, DocumentRenderPlan $plan): string
    {
        $catalog = $plan->projection(FootnoteCatalog::class)
            ?? throw new \LogicException('Missing footnote projection.');

        return $catalog->referenceMarker(
            $context->node->string('label'),
            $context->escapeText($context->source()),
        );
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return $context->source();
    }
}
