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
 * The outcome of a conformance run: per-section tallies in first-seen order
 * plus the overall passed and total counts.
 */
final readonly class ConformanceResult
{
    /**
     * @param list<SectionResult> $sections
     */
    public function __construct(
        public array $sections,
        public int $passed,
        public int $total,
    ) {
    }
}
