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

namespace Alto\Markdown\Extension\ContentSlicer;

use Alto\Markdown\Extension\Document\DocumentProjectionTransform;
use Alto\Markdown\Extension\Document\DocumentRenderProjection;
use Alto\Markdown\Extension\Document\DocumentTransformContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ContentSlicerTransform implements DocumentProjectionTransform
{
    private ?ContentSlicerLayout $layout = null;

    public function __construct(private readonly int $minLevel)
    {
    }

    public function transform(DocumentTransformContext $context): void
    {
        if (!$context->rendersCompleteDocument()) {
            $this->layout = new ContentSlicerLayout([], 0);

            return;
        }

        $before = [];
        $levels = [];

        foreach ($context->headings() as $heading) {
            if (0 !== $heading->depth) {
                continue;
            }

            $level = $context->renderedHeadingLevel($heading);
            $closingCount = 0;

            while ([] !== $levels && $level <= $levels[array_key_last($levels)]) {
                array_pop($levels);
                ++$closingCount;
            }

            $html = str_repeat("</section>\n", $closingCount);
            if ($level >= $this->minLevel) {
                $html .= "<section>\n";
                $levels[] = $level;
            }

            if ('' !== $html) {
                $before[$context->headingOrdinal($heading)] = $html;
            }
        }

        $this->layout = new ContentSlicerLayout($before, \count($levels));
    }

    public function projection(): DocumentRenderProjection
    {
        return $this->layout
            ?? throw new \LogicException('The content slicer transform has not run.');
    }
}
