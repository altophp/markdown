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

use Alto\Markdown\Extension\Document\DocumentRenderProjection;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TableOfContentsCatalog implements DocumentRenderProjection
{
    /**
     * @var array<int, int>
     */
    private array $markerIndexes;

    /**
     * @param list<TableOfContentsHeading> $headings
     * @param list<int>                    $markerOffsets
     */
    public function __construct(
        public array $headings,
        array $markerOffsets,
    ) {
        $indexes = [];

        foreach ($markerOffsets as $index => $offset) {
            $indexes[$offset] = $index;
        }

        $this->markerIndexes = $indexes;
    }

    public function markerIndex(int $startOffset): int
    {
        return $this->markerIndexes[$startOffset]
            ?? throw new \LogicException(\sprintf('Source offset %d is not a table of contents marker.', $startOffset));
    }

    public static function targetId(string $slug): string
    {
        if ('' === $slug) {
            return 'toc-heading';
        }

        return str_starts_with($slug, 'toc-heading')
            ? 'toc-heading-' . $slug
            : $slug;
    }
}
