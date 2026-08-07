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

namespace Alto\Markdown\Extension\Inline;

/**
 * Modifies the HTML already emitted by earlier inline siblings.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface AccumulatingHtmlInlineRenderer extends HtmlInlineRenderer
{
    public function accumulate(HtmlInlineOutputContext $context, string $html): string;
}
