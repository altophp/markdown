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

use Alto\Markdown\Extension\Document\DocumentRenderPlan;

/**
 * Renders one rich block's inline content (or one rich table cell) to
 * HTML for the block render engine. The workspace lane walks a cached
 * inline tape (HtmlInlineRenderer); the direct lane emits HTML during
 * the inline scan (FusedInlineRenderer). Both produce byte-identical
 * output.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface BlockInlineRenderer
{
    public function useDocumentRenderPlan(?DocumentRenderPlan $plan): void;

    public function renderBlockInlines(HtmlRenderSource $source, int $blockOrdinal, bool $hardBreaks, HtmlPolicy $policy): string;

    /**
     * Render a heading through the reusable tape lane when heading semantics
     * have already materialized its inline tape.
     */
    public function renderHeadingInlines(HtmlRenderSource $source, int $blockOrdinal, bool $hardBreaks, HtmlPolicy $policy): string;

    public function renderMarkdown(InlineMarkdownRenderSource $source, string $markdown, HtmlPolicy $policy): string;
}
