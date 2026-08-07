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

namespace Alto\Markdown\Extension;

use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\Block\MarkdownBlockPrinter;

/**
 * Renderer bindings for built-in block constructs that use compiled native
 * parsers instead of the public block adapter.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface NativeBlockOutputExtensionInterface
{
    /**
     * @return array<int, HtmlBlockRenderer>
     */
    public function nativeHtmlBlockRenderers(): array;

    /**
     * @return array<int, MarkdownBlockPrinter>
     */
    public function nativeMarkdownBlockPrinters(): array;
}
