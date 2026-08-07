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

namespace Alto\Markdown\Extension\Include;

use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\Block\MarkdownBlockOutputContext;
use Alto\Markdown\Extension\Block\MarkdownBlockPrinter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class IncludeOutput implements HtmlBlockRenderer, MarkdownBlockPrinter
{
    public function __construct(private IncludePolicy $policy)
    {
    }

    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        unset($children);

        return $context->renderMarkdown(
            $context->state()->string('content'),
            $this->policy->parseOptions(),
        );
    }

    public function print(MarkdownBlockOutputContext $context, string $children): string
    {
        unset($children);

        return $context->source();
    }
}
