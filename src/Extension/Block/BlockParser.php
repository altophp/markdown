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
interface BlockParser
{
    /**
     * Bytes that can start this construct at the first non-space offset.
     */
    public function triggerBytes(): string;

    public function tryStart(BlockStartContext $context): ?BlockStartResult;

    public function tryContinue(BlockContinueContext $context): BlockContinueResult;
}
