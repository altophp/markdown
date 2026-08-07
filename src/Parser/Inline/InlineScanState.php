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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Extension\Inline\InlineNode;

/**
 * The cursor-and-emission contract inline constructs are written against.
 * A construct reads the joined content through content() and offset(),
 * and consumes bytes by emitting a node covering [offset(), $end): the
 * tape-building implementation (InlineState) appends a tape node, the
 * fused HTML emitter appends the node's rendered form instead. Both move
 * the cursor past $end and flush the pending text run first.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface InlineScanState
{
    public function content(): InlineContent;

    /**
     * Content offset of the cursor (an offset into content()->text).
     */
    public function offset(): int;

    /**
     * Emits a node covering the content range [offset, $end) and moves
     * the cursor past it. Pending text is flushed first. Returns the
     * node's ordinal (implementations without node identity return 0).
     */
    public function emit(int $kind, int $end, int $flags = 0, ?string $payload = null): int;

    /**
     * Emits one leaf node owned by a public inline extension.
     */
    public function emitExtension(int $kind, int $end, InlineNode $node): void;
}
