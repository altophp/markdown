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

use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;

/**
 * Optional hook for paragraph-replacing constructs that must inspect the
 * paragraph they would absorb.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface ParagraphReplacementValidator
{
    public function canReplaceParagraph(ParserState $state, ParseTape $tape, int $paragraph, BlockStart $start): bool;
}
