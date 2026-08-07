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

use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;

/**
 * Renderer bindings for built-in inline constructs whose trigger is owned by
 * core Markdown syntax.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface NativeInlineOutputExtensionInterface
{
    /**
     * @return array<int, HtmlInlineRenderer>
     */
    public function nativeHtmlInlineRenderers(): array;

    /**
     * @return array<int, MarkdownInlinePrinter>
     */
    public function nativeMarkdownInlinePrinters(): array;
}
