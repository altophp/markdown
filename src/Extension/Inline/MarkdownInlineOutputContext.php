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

use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MarkdownInlineOutputContext extends InlineOutputContext
{
    /**
     * @internal Alto creates output contexts for registered printers
     */
    public function __construct(
        InlineNode $node,
        ?SourceRange $range,
        string $source,
        public MarkdownStyle $style,
    ) {
        parent::__construct($node, $range, $source);
    }
}
