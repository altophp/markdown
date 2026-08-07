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
use Alto\Markdown\Parser\ReadOnlyParseTape;

/**
 * Minimal read contract shared by workspace-backed and direct HTML rendering.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface HtmlRenderSource extends InlineMarkdownRenderSource
{
    public function htmlSourceBytes(): string;

    public function htmlTape(): ReadOnlyParseTape;

    public function htmlRootOrdinal(): int;

    public function inlineSourceView(int $ordinal): InlineSourceView;

    public function inlineTapeView(int $blockOrdinal, InlineSourceView $source): InlineTapeView;

    /**
     * Document-wide unique GitHub-style slug for one heading.
     */
    public function headingSlug(int $ordinal): string;

    public function codeBlockLanguage(int $ordinal): ?string;

    public function codeBlockCode(int $ordinal): string;

    /**
     * @return array{string|null, string, string}
     */
    public function codeBlockParts(int $ordinal): array;

    /**
     * The verbatim source of an HTML block, container markers excluded.
     */
    public function htmlBlockSource(int $ordinal): string;

    /**
     * @return array{list<string>, list<string>, list<list<string>>}
     */
    public function tableParts(int $ordinal): array;
}
