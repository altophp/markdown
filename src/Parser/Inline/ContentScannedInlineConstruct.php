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

/**
 * An inline construct whose match is decided by scanning text content, not by a
 * single distinguishing trigger byte (its trigger bytes over-approximate). It
 * declares the substrings that must be present for a match to be possible, so a
 * plain-text fast path can conservatively detect "no match here".
 *
 * Its trigger bytes never join a scanner's special-byte set: an
 * over-approximating set (every ASCII letter and digit, say) would stop the
 * literal-skipping jump on nearly every byte of ordinary prose. The scanner
 * asks nextCandidate() where the construct could start instead, which is a
 * substring search over the same content triggers.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface ContentScannedInlineConstruct extends InlineConstruct
{
    /**
     * @return list<string>
     */
    public function contentScanTriggers(): array;

    /**
     * The smallest offset at or after $from where tryParse() could succeed,
     * or -1 when the rest of $text holds no candidate. Must not under-report:
     * every offset where tryParse() would consume bytes has to be reachable
     * by walking these candidates forward.
     */
    public function nextCandidate(string $text, int $from): int;
}
