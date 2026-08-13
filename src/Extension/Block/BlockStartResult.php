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
final readonly class BlockStartResult
{
    /**
     * Both offsets address original input bytes. The engine validates them
     * against the current line before mutating parser state.
     */
    public function __construct(
        public int $startOffset,
        public int $advanceOffset,
        public bool $container = false,
        public BlockState $state = new BlockState(),
    ) {}
}
