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

use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\Html\CompiledHtmlDecoratorChain;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;

/**
 * One open container on the explicit HTML render stack.
 *
 * The block HTML walk is iterative so depth is heap-bound, not C-stack-bound.
 * Simple containers (blockquote, alert, list) accumulate their children into
 * {@see $buf} incrementally and close with {@see $suffix}; a list item collects
 * its children into {@see $parts} and assembles the tag on exit, mirroring the
 * original recursive `listItem()`.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HtmlBlockFrame
{
    public const int QUOTE = 0;
    public const int ALERT = 1;
    public const int LIST = 2;
    public const int ITEM = 3;
    public const int CUSTOM = 4;
    public const int DESCRIPTION = 5;

    public int $cursor;

    public string $buf = '';

    public string $suffix = '';

    /**
     * @var list<string>
     */
    public array $parts = [];

    public bool $firstIsInline = false;

    public bool $lastIsInline = false;

    public bool $first = true;

    /**
     * Task list marker for an item frame, cleared once it has been folded
     * into the item's first paragraph.
     */
    public string $task = '';

    public string $tag = 'li';

    public ?HtmlBlockRenderer $customRenderer = null;

    public ?HtmlBlockOutputContext $customContext = null;

    public ?CompiledHtmlDecoratorChain $decorator = null;

    public ?HtmlNodeOutputContext $decoratorContext = null;

    public function __construct(
        public int $kind,
        public int $ordinal,
        public bool $childTight,
        int $firstChild,
    ) {
        $this->cursor = $firstChild;
    }
}
