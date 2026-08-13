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
 * The parsed opening line of a fenced code block.
 *
 * @internal
 *
 * @see SnippetExtractor
 */
final readonly class OpeningFence
{
    public function __construct(
        public string $char,
        public int $length,
        public int $indent,
    ) {}
}
