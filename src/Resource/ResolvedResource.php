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

namespace Alto\Markdown\Resource;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ResolvedResource
{
    public function __construct(
        public string $id,
        public string $bytes,
    ) {
        if ('' === $id || str_contains($id, "\x00")) {
            throw new InvalidMarkdownArgumentException('Resolved resource ID must not be empty or contain null bytes.');
        }
    }
}
