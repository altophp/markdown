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

namespace Alto\Markdown\Extension\Embed;

use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\Block\MarkdownBlockOutputContext;
use Alto\Markdown\Extension\Block\MarkdownBlockPrinter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class EmbedOutput implements HtmlBlockRenderer, MarkdownBlockPrinter
{
    public function __construct(private EmbedPolicy $policy) {}

    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        unset($children);

        $html = $context->state()->value('html');
        if (\is_string($html) && $context->allowsRawHtml()) {
            if ('' === $html || str_ends_with($html, "\n") || str_ends_with($html, "\r")) {
                return $html;
            }

            return $html . "\n";
        }

        if (EmbedFallback::Remove === $this->policy->fallback) {
            return '';
        }

        $url = $context->state()->string('url');

        return '<p><a href="' . $context->escapeUrl($url) . '">'
            . $context->escapeText($url)
            . "</a></p>\n";
    }

    public function print(MarkdownBlockOutputContext $context, string $children): string
    {
        unset($children);

        return $context->source();
    }
}
