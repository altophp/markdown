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

namespace Alto\Markdown\Operation;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DiffHunk
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public int $originalStartLine,
        public int $originalLineCount,
        public int $editedStartLine,
        public int $editedLineCount,
        public array $lines,
    ) {}
}
