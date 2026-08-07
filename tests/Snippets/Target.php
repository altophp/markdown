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

namespace Alto\Markdown\Tests\Snippets;

/**
 * A documentation file to scan for snippets.
 *
 * @internal
 *
 * @see SnippetChecker
 */
final readonly class Target
{
    public function __construct(
        public string $path,
        public string $display,
        public bool $isDefault,
    ) {
    }
}
