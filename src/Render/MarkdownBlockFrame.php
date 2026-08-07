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
 * One open container on the explicit Markdown printer stack.
 *
 * The block printer walk is iterative so depth is heap-bound, not
 * C-stack-bound. Each frame collects its already-printed children into
 * {@see $parts}; on exit the driver stashes those parts and calls the matching
 * block printer, which reads them back through the render context instead of
 * recursing.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class MarkdownBlockFrame
{
    public const int DOCUMENT = 0;
    public const int QUOTE = 1;
    public const int ALERT = 2;
    public const int PLAIN_ITEM = 3;
    public const int LIST = 4;
    public const int ITEM = 5;

    public int $cursor;

    /**
     * @var list<string>
     */
    public array $parts = [];

    public int $number = 0;

    public bool $ordered = false;

    public bool $loose = false;

    public string $marker = '';

    public function __construct(
        public int $kind,
        public int $ordinal,
        int $firstChild,
    ) {
        $this->cursor = $firstChild;
    }
}
