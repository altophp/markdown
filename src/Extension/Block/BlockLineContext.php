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

namespace Alto\Markdown\Extension\Block;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface BlockLineContext
{
    /**
     * Original input byte offset of the current line.
     */
    public function lineStartOffset(): int;

    /**
     * Original input byte offset immediately after the line content, excluding
     * its line ending.
     */
    public function lineContentEndOffset(): int;

    /**
     * Original input byte offset of the first non-space byte.
     */
    public function firstNonSpaceOffset(): int;

    /**
     * Indentation in virtual columns relative to the current container.
     */
    public function indentColumns(): int;

    /**
     * Read an original input byte as an integer from 0 to 255.
     */
    public function byteAt(int $offset): int;

    /**
     * Read an original input byte range within the current line.
     */
    public function slice(int $startOffset, int $endOffset): string;
}
