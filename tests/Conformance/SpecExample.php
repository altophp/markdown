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

namespace Alto\Markdown\Tests\Conformance;

/**
 * A single CommonMark or GFM spec example: the Markdown input and the exact
 * HTML the reference implementation produces for it.
 */
final readonly class SpecExample
{
    public function __construct(
        public string $markdown,
        public string $html,
        public int $example,
        public string $section,
        public int $startLine,
        public int $endLine,
    ) {}
}
