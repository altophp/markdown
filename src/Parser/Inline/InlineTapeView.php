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

use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParseTape;

/**
 * One immutable access point for a parsed inline block.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineTapeView
{
    public function __construct(
        public SourceBuffer $buffer,
        public ParseTape $tape,
    ) {}
}
