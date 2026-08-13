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

namespace Alto\Markdown\Extension\Block;

use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
abstract readonly class BlockOutputContext
{
    /**
     * @internal Alto creates output contexts for registered renderers
     */
    public function __construct(
        private BlockState $state,
        public SourceRange $range,
        private string $sourceBytes,
    ) {}

    public function state(): BlockState
    {
        return $this->state;
    }

    /**
     * Original input bytes covered by this block's source range.
     */
    public function source(): string
    {
        return substr(
            $this->sourceBytes,
            $this->range->startOffset,
            $this->range->endOffset - $this->range->startOffset,
        );
    }
}
