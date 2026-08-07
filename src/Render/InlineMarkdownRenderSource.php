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

use Alto\Markdown\Parser\Inline\InlineSourceView;
use Alto\Markdown\Parser\Inline\InlineTapeView;
use Alto\Markdown\Parser\InlineCountBudget;
use Alto\Markdown\Parser\ReferenceMap;
use Alto\Markdown\Profile\CompiledProfile;

/**
 * Minimal source contract for rendering a standalone inline fragment.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface InlineMarkdownRenderSource
{
    public function compiledProfile(): CompiledProfile;

    public function referenceMap(): ReferenceMap;

    public function inlineCountBudget(): ?InlineCountBudget;

    public function inlineMarkdownSourceView(string $markdown): InlineSourceView;

    public function inlineMarkdownTapeView(string $markdown): InlineTapeView;

    public function inlineMarkdownRangesAreOriginal(): bool;

    public function tableCellHtmlCache(): ?TableCellHtmlCache;
}
