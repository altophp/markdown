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

namespace Alto\Markdown\Tests\Support;

use Alto\Markdown\Source\SourceRange;

final readonly class SourceEditExpectation
{
    /**
     * @param list<SourceRange> $originalTouchedRanges
     * @param list<SourceRange> $editedTouchedRanges
     */
    public function __construct(
        public string $originalBytes,
        public string $editedBytes,
        public string $expectedSemanticBytes,
        public array $originalTouchedRanges,
        public array $editedTouchedRanges,
    ) {
    }
}
