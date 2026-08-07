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

namespace Alto\Markdown\Stats;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SectionWordCount
{
    public function __construct(
        public string $title,
        public int $level,
        public int $wordCount,
    ) {
    }
}
