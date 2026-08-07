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
 * One inline construct, one class, one file, mirroring the block layer.
 * The scanner consults a construct only when the byte at the cursor is in
 * its trigger set; tryParse either consumes bytes (emitting nodes through
 * the state) and returns true, or leaves the state untouched and returns
 * false so the byte joins the current text run.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface InlineConstruct
{
    /**
     * The bytes that can start this construct.
     */
    public function triggerBytes(): string;

    public function tryParse(InlineScanState $state): bool;
}
