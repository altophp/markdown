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

/**
 * One emphasis delimiter run collected during the scan: the TEXT node
 * holding the run and what the flanking rules allow it to do. Length
 * shrinks as process-emphasis consumes characters; a spent delimiter
 * (length 0) is inert and its node is unlinked.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Delimiter
{
    public bool $active = true;

    public function __construct(
        public int $node,
        public readonly string $char,
        public int $length,
        public readonly bool $canOpen,
        public readonly bool $canClose,
    ) {}
}
