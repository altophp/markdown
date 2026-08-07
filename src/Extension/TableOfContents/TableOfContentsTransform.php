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

namespace Alto\Markdown\Extension\TableOfContents;

use Alto\Markdown\Extension\Document\DocumentProjectionTransform;
use Alto\Markdown\Extension\Document\DocumentRenderProjection;
use Alto\Markdown\Extension\Document\DocumentTransformContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class TableOfContentsTransform implements DocumentProjectionTransform
{
    private ?TableOfContentsCatalog $catalog = null;

    public function transform(DocumentTransformContext $context): void
    {
        $markerOffsets = [];

        foreach ($context->blocks() as $block) {
            if ('table-of-contents:block' === $block->kind) {
                $markerOffsets[] = $block->range->startOffset;
            }
        }

        $headings = [];

        if ([] !== $markerOffsets) {
            foreach ($context->headings() as $heading) {
                if (0 !== $heading->depth) {
                    continue;
                }

                $headings[] = new TableOfContentsHeading(
                    $context->renderedHeadingLevel($heading),
                    $context->headingText($heading),
                    $context->headingSlug($heading),
                );
            }
        }

        $this->catalog = new TableOfContentsCatalog($headings, $markerOffsets);
    }

    public function projection(): DocumentRenderProjection
    {
        return $this->catalog
            ?? throw new \LogicException('The table of contents transform has not run.');
    }
}
