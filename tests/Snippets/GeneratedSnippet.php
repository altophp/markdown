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
 * A snippet written to disk, with the mapping needed to translate a PHPStan
 * line number in the generated file back to the source documentation line.
 *
 * @internal
 *
 * @see SnippetChecker
 */
final readonly class GeneratedSnippet
{
    public function __construct(
        public string $path,
        public string $display,
        public int $fenceLine,
        public int $bodyStartLine,
        public int $strippedLines,
    ) {
    }
}
