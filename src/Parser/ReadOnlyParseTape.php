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

use Alto\Markdown\Extension\Block\BlockState;

/**
 * Read-only syntax tape contract for renderers and syntax consumers.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface ReadOnlyParseTape
{
    public function count(): int;

    /**
     * Copy-on-write column snapshot for trusted hot-loop readers. Ordinals
     * indexed into the snapshot must come from the tape itself; externally
     * supplied ordinals stay on the checked per-slot accessors below.
     */
    public function columns(): ParseTapeColumns;

    public function kindId(int $ordinal): int;

    public function parentOrdinal(int $ordinal): int;

    public function firstChildOrdinal(int $ordinal): int;

    public function nextSiblingOrdinal(int $ordinal): int;

    public function startOffset(int $ordinal): int;

    public function endOffset(int $ordinal): int;

    public function generation(int $ordinal): int;

    public function flags(int $ordinal): int;

    public function payload(int $ordinal): ?string;

    public function extensionBlockState(int $ordinal): BlockState;
}
