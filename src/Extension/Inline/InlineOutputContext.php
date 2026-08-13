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

use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
abstract readonly class InlineOutputContext
{
    /**
     * @internal Alto creates output contexts for registered renderers
     */
    public function __construct(
        public InlineNode $node,
        public ?SourceRange $range,
        private string $source,
    ) {}

    /**
     * Exact joined inline bytes matched by the parser.
     *
     * Container markers between physical lines are not part of this value.
     * range addresses original-document bytes when available. It is null for
     * detached inline inputs such as normalized GFM table cells.
     */
    public function source(): string
    {
        return $this->source;
    }
}
