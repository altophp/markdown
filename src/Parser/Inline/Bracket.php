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
 * One open bracket on the link resolution stack: the TEXT node holding
 * "[" or "![", whether it is an image opener, and where the delimiter
 * list stood when it opened (emphasis inside the label processes from
 * there). A matched link deactivates earlier link openers: no links
 * inside links.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Bracket
{
    public bool $active = true;

    public function __construct(
        public readonly int $node,
        public readonly bool $image,
        public readonly int $delimiterIndex,
        public readonly int $contentOffset,
        public readonly int $previousSibling,
    ) {}
}
