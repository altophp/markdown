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

use Alto\Markdown\Extension\Document\DocumentRootHtmlLayout;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ContentSlicerLayout implements DocumentRootHtmlLayout
{
    /**
     * @param array<int, string> $before
     */
    public function __construct(
        private array $before,
        private int $finalClosings,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->before && 0 === $this->finalClosings;
    }

    public function htmlBeforeRootBlock(int $ordinal): string
    {
        return $this->before[$ordinal] ?? '';
    }

    public function htmlAfterRootBlocks(): string
    {
        return str_repeat("</section>\n", $this->finalClosings);
    }
}
