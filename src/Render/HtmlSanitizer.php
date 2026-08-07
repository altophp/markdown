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
 * Sanitizes one complete rendered HTML fragment.
 *
 * Implementations must be deterministic and reusable. The cache key must
 * change whenever sanitization behavior changes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface HtmlSanitizer
{
    public function sanitize(string $html, HtmlPolicy $policy): string;

    public function cacheKey(): string;
}
