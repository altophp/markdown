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

namespace Alto\Markdown\Document;

use Alto\Markdown\Parser\CaseFold;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GitHubSlugger
{
    public static function slug(string $text): string
    {
        $slug = CaseFold::fold(trim($text));
        $slug = preg_replace('/[^\p{L}\p{N} -]+/u', '', $slug) ?? $slug;
        $slug = preg_replace('/ +/u', '-', $slug) ?? $slug;

        return $slug;
    }

    private function __construct() {}
}
