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

namespace Alto\Markdown\Extension\Import;

use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\Block\MarkdownBlockOutputContext;
use Alto\Markdown\Extension\Block\MarkdownBlockPrinter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ImportOutput implements HtmlBlockRenderer, MarkdownBlockPrinter
{
    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        unset($children);

        $language = $context->state()->value('language');
        $class = \is_string($language)
            ? ' class="language-'.$context->escapeAttribute($language).'"'
            : '';

        return '<pre><code'.$class.'>'
            .$context->escapeText($context->state()->string('content'))
            ."</code></pre>\n";
    }

    public function print(MarkdownBlockOutputContext $context, string $children): string
    {
        unset($children);

        return $context->source();
    }
}
