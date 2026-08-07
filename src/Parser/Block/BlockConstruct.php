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

use Alto\Markdown\Parser\ParserState;

/**
 * One block construct, one class, one file. The core loop never contains
 * per-construct logic; a wave-B implementation replaces its inert stub
 * without touching the loop, the registry order, or this interface.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface BlockConstruct
{
    /**
     * The BlockKind constant this construct owns.
     */
    public function kind(): int;

    /**
     * First-char dispatch: the bytes at the line's first non-space offset
     * that can start this construct. The loop consults tryStart only when
     * the byte matches. Null means always consult (indent-based starts);
     * the empty string means never (constructs that start via other hooks).
     */
    public function triggerBytes(): ?string;

    /**
     * Try to start a new block at the state's cursor, inside the given open
     * container. $paragraphOpen reports whether an open paragraph would be
     * interrupted: constructs that cannot interrupt a paragraph (indented
     * code; ordered lists not starting at 1) return null in that case, and
     * setext headings fire only when it is true. Returns null when the line
     * cannot start this construct; otherwise the consumed markers are
     * reflected in the returned BlockStart's contentOffset. Must not mutate
     * the tape.
     */
    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart;

    /**
     * Ask whether the open block with this ordinal continues on the current
     * line. On Matched, the construct has consumed its continuation markers
     * by advancing the state's cursor. On Closed, the whole line belongs to
     * the block and the block closes with it.
     */
    public function tryContinue(ParserState $state, int $ordinal): ContinueResult;

    /**
     * Finalize the block (set end offsets, flags, payload). Called exactly
     * once when the block leaves the open stack.
     */
    public function close(ParserState $state, int $ordinal): void;
}
