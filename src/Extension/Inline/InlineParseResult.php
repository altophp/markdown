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
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineParseResult
{
    /**
     * $endOffset addresses the block's joined inline content.
     */
    public function __construct(
        public int $endOffset,
        public InlineNode $node,
    ) {}
}
