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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Parser\Input\SourceBuffer;

/**
 * Original source buffer plus the ordered slices forming one inline block.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineSourceView
{
    /**
     * @param list<array{int, int, int}> $pairs
     */
    public function __construct(
        public SourceBuffer $buffer,
        public array $pairs,
    ) {
    }
}
