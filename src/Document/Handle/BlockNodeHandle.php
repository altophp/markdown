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

namespace Alto\Markdown\Document\Handle;

use Alto\Markdown\Builder\MarkdownFragment;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Node\Block;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Operation\BlockInsertPosition;
use Alto\Markdown\Operation\BlockWrapper;
use Alto\Markdown\Query\Collection;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
class BlockNodeHandle extends TapeNodeHandle implements Block
{
    public function insertBefore(MarkdownFragment|string $content): Collection
    {
        $this->assertExists();

        return new BlockInsertion($this->model)->apply($this->id(), $content, BlockInsertPosition::Before);
    }

    public function insertAfter(MarkdownFragment|string $content): Collection
    {
        $this->assertExists();

        return new BlockInsertion($this->model)->apply($this->id(), $content, BlockInsertPosition::After);
    }

    public function moveBefore(Block $anchor): static
    {
        $this->assertExists();
        $this->repin(new BlockManipulation($this->model)->move(
            $this->id(),
            $this->anchorId($anchor),
            BlockInsertPosition::Before,
        ));

        return $this;
    }

    public function moveAfter(Block $anchor): static
    {
        $this->assertExists();
        $this->repin(new BlockManipulation($this->model)->move(
            $this->id(),
            $this->anchorId($anchor),
            BlockInsertPosition::After,
        ));

        return $this;
    }

    public function cloneBefore(Block $anchor): Block
    {
        $this->assertExists();

        return new BlockManipulation($this->model)->copy(
            $this->id(),
            $this->anchorId($anchor),
            BlockInsertPosition::Before,
        );
    }

    public function cloneAfter(Block $anchor): Block
    {
        $this->assertExists();

        return new BlockManipulation($this->model)->copy(
            $this->id(),
            $this->anchorId($anchor),
            BlockInsertPosition::After,
        );
    }

    public function replaceWith(MarkdownFragment|string $content): Collection
    {
        $this->assertExists();

        return new BlockManipulation($this->model)->replace($this->id(), $content);
    }

    public function wrap(BlockWrapper $wrapper): Block
    {
        $this->assertExists();

        return new BlockManipulation($this->model)->wrap($this->id(), $wrapper);
    }

    private function anchorId(Block $anchor): NodeId
    {
        if (!$anchor instanceof self || $anchor->model !== $this->model) {
            throw new InvalidMarkdownArgumentException('A block destination must belong to the same document.');
        }

        $anchor->assertExists();

        return $anchor->id();
    }
}
