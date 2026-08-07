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

namespace Alto\Markdown\Extension\Inline;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface InlineParser
{
    /**
     * Return the one byte that can start this syntax.
     */
    public function triggerByte(): string;

    public function tryParse(InlineParseContext $context): ?InlineParseResult;
}
