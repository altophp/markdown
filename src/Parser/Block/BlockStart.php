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

namespace Alto\Markdown\Parser\Block;

use Alto\Markdown\Extension\Block\BlockState;

/**
 * A successful block start: which kind opened and where its content begins.
 *
 * contentOffset is the byte offset (original input bytes, BOM included) of
 * the first byte after the consumed start markers. isContainer marks blocks
 * that can hold further blocks, so the start loop keeps matching inside
 * them. replacesParagraph is the setext case: the new block absorbs the
 * open paragraph instead of nesting under the same parent.
 *
 * paragraphWrapperKind and paragraphChildKind describe the stricter
 * container replacement used by definition lists: the old paragraph is
 * split into closed child nodes, and the construct itself opens after
 * those children. They are internal parser metadata and are ignored by
 * ordinary paragraph replacements.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlockStart
{
    public function __construct(
        public int $kind,
        public int $contentOffset,
        public bool $isContainer = false,
        public bool $replacesParagraph = false,
        public int $flags = 0,
        public ?string $payload = null,
        public int $pad = 0,
        public ?int $startOffset = null,
        public ?BlockState $extensionState = null,
        public ?int $paragraphWrapperKind = null,
        public ?int $paragraphChildKind = null,
    ) {}
}
