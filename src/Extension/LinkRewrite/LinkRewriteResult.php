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

namespace Alto\Markdown\Extension\LinkRewrite;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LinkRewriteResult
{
    public function __construct(
        public int $rewritten,
        public int $unchanged,
        public int $skippedReferences,
        public int $skippedOverlaps,
    ) {}

    public function hasChanges(): bool
    {
        return $this->rewritten > 0;
    }
}
