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

namespace Alto\Markdown\Parser\Block;

/**
 * Integer-backed block node kinds recorded on the parse tape.
 *
 * Values are stable identifiers written into the tape and read back by
 * renderers and later passes. Reserve every V1 block kind now so wave-B
 * construct implementations do not need to touch this file.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BlockKind
{
    public const int DOCUMENT = 1;
    public const int PARAGRAPH = 2;
    public const int ATX_HEADING = 3;
    public const int SETEXT_HEADING = 4;
    public const int INDENTED_CODE = 5;
    public const int FENCED_CODE = 6;
    public const int HTML_BLOCK = 7;
    public const int BLOCK_QUOTE = 8;
    public const int LIST = 9;
    public const int LIST_ITEM = 10;
    public const int THEMATIC_BREAK = 11;
    public const int LINK_REFERENCE_DEFINITION = 12;

    private function __construct()
    {
    }
}
