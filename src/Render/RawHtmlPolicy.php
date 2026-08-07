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

/**
 * How raw HTML (HTML blocks and inline raw HTML) is handled at emission.
 *
 * - Escape: HTML-escaped and shown as visible text (the safe default).
 * - Strip: removed from the output entirely.
 * - Allow: passed through verbatim (CommonMark passthrough); the GFM
 *   tagfilter keeps its spec-mode behaviour under this mode only.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
enum RawHtmlPolicy
{
    case Escape;
    case Strip;
    case Allow;
}
