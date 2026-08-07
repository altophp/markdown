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

namespace Alto\Markdown\Builder;

use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Render\RenderOptions;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface MarkdownBuilder
{
    public function heading(int $level, string $text): self;

    public function h1(string $text): self;

    public function h2(string $text): self;

    public function paragraph(string $text): self;

    public function codeBlock(?string $language, string $code): self;

    /**
     * @param iterable<string> $items
     */
    public function unorderedList(iterable $items): self;

    /**
     * @param iterable<string> $items
     */
    public function orderedList(iterable $items): self;

    public function blockquote(string $text): self;

    public function thematicBreak(): self;

    public function document(): MarkdownDocument;

    public function toMarkdown(?RenderOptions $options = null): string;
}
