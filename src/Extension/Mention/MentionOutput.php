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

namespace Alto\Markdown\Extension\Mention;

use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MentionOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        $title = $context->node->attribute('title');
        $titleAttribute = \is_string($title)
            ? ' title="' . $context->escapeAttribute($title) . '"'
            : '';

        return '<a href="' . $context->escapeUrl($context->node->string('url')) . '"' . $titleAttribute . '>'
            . $context->escapeText($context->node->text)
            . '</a>';
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return $context->source();
    }
}
