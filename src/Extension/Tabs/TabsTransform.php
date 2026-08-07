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

namespace Alto\Markdown\Extension\Tabs;

use Alto\Markdown\Extension\Document\DocumentProjectionTransform;
use Alto\Markdown\Extension\Document\DocumentRenderProjection;
use Alto\Markdown\Extension\Document\DocumentTransformBlock;
use Alto\Markdown\Extension\Document\DocumentTransformContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class TabsTransform implements DocumentProjectionTransform
{
    private ?TabsCatalog $catalog = null;

    /**
     * @var array<int, DocumentTransformBlock>
     */
    private array $ancestors = [];

    public function transform(DocumentTransformContext $context): void
    {
        $this->catalog = new TabsCatalog();
        $this->ancestors = [];

        foreach ($context->blocks() as $block) {
            $this->ancestors = \array_slice($this->ancestors, 0, $block->depth);
            $parent = $block->depth > 0 ? ($this->ancestors[$block->depth - 1] ?? null) : null;
            $offset = $block->range->startOffset;

            if (TabsExtension::GROUP_KIND === $block->kind) {
                $this->catalog->registerGroup($offset);
            } elseif (
                TabsExtension::ITEM_KIND === $block->kind
                && $parent instanceof DocumentTransformBlock
                && TabsExtension::GROUP_KIND === $parent->kind
            ) {
                $this->catalog->registerItem($parent->range->startOffset, $offset);
            }

            $this->ancestors[$block->depth] = $block;
        }
    }

    public function projection(): DocumentRenderProjection
    {
        return $this->catalog
            ?? throw new \LogicException('The tabs transform has not run.');
    }
}
