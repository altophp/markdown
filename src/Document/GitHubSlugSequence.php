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

/**
 * Allocates document-wide unique GitHub-style heading slugs.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GitHubSlugSequence
{
    /**
     * @var array<string, int>
     */
    private array $nextSuffix = [];

    /**
     * @var array<string, true>
     */
    private array $used = [];

    public function next(string $text): string
    {
        $base = GitHubSlugger::slug($text);
        $suffix = $this->nextSuffix[$base] ?? 0;
        $slug = 0 === $suffix ? $base : $base . '-' . $suffix;

        while (isset($this->used[$slug])) {
            ++$suffix;
            $slug = $base . '-' . $suffix;
        }

        $this->nextSuffix[$base] = $suffix + 1;
        $this->used[$slug] = true;

        return $slug;
    }
}
