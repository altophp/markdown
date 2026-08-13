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

namespace Alto\Markdown\Extension\PairedDelimiter;

use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class PairedDelimiterOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function __construct(private string $element) {}

    public function render(HtmlInlineOutputContext $context): string
    {
        return '<' . $this->element . '>' . $context->escapeText($context->node->text) . '</' . $this->element . '>';
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return $context->source();
    }
}
