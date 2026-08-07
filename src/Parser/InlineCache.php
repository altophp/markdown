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

/**
 * Per-document cache of inline tapes, keyed by block ordinal. An entry is
 * valid only for the generation it was parsed at: a block edit bumps the
 * block's generation stamp and the stale entry is dropped on next read
 * (SPEC sections 2 and 3).
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InlineCache
{
    /**
     * @var array<int, array{int, ParseTape}>
     */
    private array $entries = [];

    public function __construct()
    {
        ++Instrumentation::$inlineCaches;
    }

    public function get(int $blockOrdinal, int $generation): ?ParseTape
    {
        $entry = $this->entries[$blockOrdinal] ?? null;

        if (null === $entry) {
            return null;
        }

        if ($entry[0] !== $generation) {
            unset($this->entries[$blockOrdinal]);

            return null;
        }

        ++Instrumentation::$cacheHits;

        return $entry[1];
    }

    public function put(int $blockOrdinal, int $generation, ParseTape $tape): void
    {
        $this->entries[$blockOrdinal] = [$generation, $tape];
    }
}
