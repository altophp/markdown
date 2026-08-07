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
 * A fenced PHP example lifted verbatim from a documentation file.
 *
 * @see SnippetExtractor
 */
final readonly class Snippet
{
    public function __construct(
        public int $line,
        public string $code,
    ) {
    }
}
