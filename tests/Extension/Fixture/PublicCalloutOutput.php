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

use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\Block\MarkdownBlockOutputContext;
use Alto\Markdown\Extension\Block\MarkdownBlockPrinter;

final readonly class PublicCalloutOutput implements HtmlBlockRenderer, MarkdownBlockPrinter
{
    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        $label = $context->escapeAttribute($context->state()->string('label'));

        return '<aside class="callout callout-' . $label . '">' . "\n" . $children . "</aside>\n";
    }

    public function print(MarkdownBlockOutputContext $context, string $children): string
    {
        $fence = str_repeat(':', $context->state()->int('fence'));

        return $fence . $context->state()->string('label')
            . ('' === $children ? '' : "\n" . $children)
            . "\n" . $fence;
    }
}
