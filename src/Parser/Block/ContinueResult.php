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
 * Outcome of asking an open block whether it continues on the current line.
 *
 * Matched: the block stays open; continuation markers were consumed.
 * NotMatched: the line does not continue this block.
 * Closed: the block consumed this line and closes with it (e.g. a closing
 * code fence); no further content from the line belongs to any block.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum ContinueResult
{
    case Matched;
    case NotMatched;
    case Closed;
}
