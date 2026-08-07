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

namespace Alto\Markdown\Parser;

use Alto\Markdown\Extension\Inline\InlineNode;

/**
 * Copy-on-write snapshot of every parse tape column.
 *
 * Trusted internal render readers grab one snapshot per walk and index the
 * arrays directly inside their loops instead of paying one bounds-checked
 * accessor call per column read. The snapshot holds the tape arrays by value:
 * PHP copy-on-write means pure reads copy nothing, and a later tape mutation
 * cannot reach into a snapshot that was already taken. Ordinals indexed into
 * a snapshot must come from the tape itself (root ordinal or link columns);
 * externally supplied ordinals stay on the checked ReadOnlyParseTape
 * accessors.
 *
 * The sentinel for "no link" in the link columns is ParseTape::NONE (-1).
 * The payload column is sparse: only ordinals with a payload have a key.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ParseTapeColumns
{
    /**
     * @param array<int, int>        $kind
     * @param array<int, int>        $parent
     * @param array<int, int>        $firstChild
     * @param array<int, int>        $nextSibling
     * @param array<int, int>        $startOffset
     * @param array<int, int>        $endOffset
     * @param array<int, int>        $generation
     * @param array<int, int>        $flags
     * @param array<int, string>     $payload
     * @param array<int, InlineNode> $extensionInlineNode
     */
    public function __construct(
        public array $kind,
        public array $parent,
        public array $firstChild,
        public array $nextSibling,
        public array $startOffset,
        public array $endOffset,
        public array $generation,
        public array $flags,
        public array $payload,
        public array $extensionInlineNode,
    ) {
    }
}
