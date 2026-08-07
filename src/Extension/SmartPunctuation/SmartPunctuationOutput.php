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

namespace Alto\Markdown\Extension\SmartPunctuation;

use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SmartPunctuationOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        return $context->escapeText($context->node->text);
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return $context->source();
    }
}
