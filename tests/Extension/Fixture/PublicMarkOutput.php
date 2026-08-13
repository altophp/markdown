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

namespace Alto\Markdown\Tests\Extension\Fixture;

use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;

final readonly class PublicMarkOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        return '<mark data-label="' . $context->escapeAttribute($context->node->string('label')) . '">'
            . $context->escapeText($context->node->text)
            . '</mark>';
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return '^^' . $context->node->text . '^^';
    }
}
