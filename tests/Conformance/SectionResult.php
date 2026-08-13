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
 * How many examples in one spec section rendered exactly as expected.
 */
final readonly class SectionResult
{
    public function __construct(
        public string $section,
        public int $passed,
        public int $total,
    ) {}
}
