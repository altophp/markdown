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

namespace Alto\Markdown\Parser\Inline;

/**
 * Integer-backed inline node kinds recorded on a block's inline tape.
 * Reserved now so wave-2 and wave-3 constructs never touch this file.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InlineKind
{
    public const int ROOT = 1;
    public const int TEXT = 2;
    public const int SOFT_BREAK = 3;
    public const int HARD_BREAK = 4;
    public const int CODE_SPAN = 5;
    public const int EMPHASIS = 6;
    public const int STRONG = 7;
    public const int LINK = 8;
    public const int IMAGE = 9;
    public const int AUTOLINK = 10;
    public const int HTML_INLINE = 11;
    public const int STRIKETHROUGH = 12;

    private function __construct()
    {
    }
}
