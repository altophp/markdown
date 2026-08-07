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

namespace Alto\Markdown\Builder;

use Alto\Markdown\Render\RenderOptions;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ParsedMarkdownFragment implements MarkdownFragment
{
    public function __construct(private string $markdown)
    {
    }

    public function toMarkdown(?RenderOptions $options = null): string
    {
        return $this->markdown;
    }
}
