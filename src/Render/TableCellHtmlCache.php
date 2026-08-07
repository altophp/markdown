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

namespace Alto\Markdown\Render;

use Alto\Markdown\Parser\Instrumentation;

/**
 * Per-document cache of rendered rich table cell HTML for the workspace
 * (warm) render lane. Table cells are not blocks: they come from a table
 * block's row payload, so the block-keyed InlineCache never covers them and
 * every warm render used to re-parse and re-render each rich cell.
 *
 * Rendering one cell is a pure function of the cell's Markdown, the document
 * profile, the reference map, and the HTML policy. Profile and reference map
 * are fixed for a document's lifetime (a rebase rebuilds both and drops this
 * cache); the policy varies per render call, so callers namespace the key
 * with HtmlPolicy::cacheKey() ahead of the cell Markdown. The same cell text
 * under the same policy always renders to the same HTML; identical cells
 * share one entry, and cached bytes are never served across differing
 * policies. An edit that replaces a table produces new cell text, which
 * misses and renders fresh; stale entries are never read wrongly and are
 * bounded by the count of distinct (policy, cell) pairs.
 *
 * Only rich cells reach this cache: plain cells take the engine's plain-scan
 * fast path and never call the inline renderer.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class TableCellHtmlCache
{
    /**
     * @var array<string, string>
     */
    private array $entries = [];

    public function get(string $cell): ?string
    {
        $html = $this->entries[$cell] ?? null;

        if (null === $html) {
            ++Instrumentation::$tableCellHtmlMisses;

            return null;
        }

        ++Instrumentation::$tableCellHtmlHits;

        return $html;
    }

    public function put(string $cell, string $html): void
    {
        $this->entries[$cell] = $html;
    }
}
