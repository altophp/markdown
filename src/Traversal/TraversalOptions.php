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

namespace Alto\Markdown\Traversal;

use Alto\Markdown\Node\Id\NodeId;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TraversalOptions
{
    public function __construct(
        public ?NodeId $root = null,
        public bool $includeInlines = false,
        public bool $includeSourceLines = false,
    ) {}
}
