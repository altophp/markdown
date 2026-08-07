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

namespace Alto\Markdown\Node;

use Alto\Markdown\Builder\MarkdownFragment;
use Alto\Markdown\Operation\BlockWrapper;
use Alto\Markdown\Query\Collection;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface Block extends NodeHandle
{
    /**
     * @return Collection<Block>
     */
    public function insertBefore(MarkdownFragment|string $content): Collection;

    /**
     * @return Collection<Block>
     */
    public function insertAfter(MarkdownFragment|string $content): Collection;

    public function moveBefore(self $anchor): static;

    public function moveAfter(self $anchor): static;

    public function cloneBefore(self $anchor): self;

    public function cloneAfter(self $anchor): self;

    /**
     * Replace this block with zero or more top-level blocks.
     *
     * @return Collection<Block>
     */
    public function replaceWith(MarkdownFragment|string $content): Collection;

    /**
     * Replace this block with one safe CommonMark container.
     */
    public function wrap(BlockWrapper $wrapper): self;
}
