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

namespace Alto\Markdown\Node;

use Alto\Markdown\Builder\MarkdownFragment;
use Alto\Markdown\MarkdownDocument;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface Section extends NodeHandle
{
    public function title(): string;

    public function exists(): bool;

    public function rename(string $title): self;

    public function remove(): MarkdownDocument;

    public function append(MarkdownFragment|string $content): self;

    public function prepend(MarkdownFragment|string $content): self;

    public function replaceBody(MarkdownFragment|string $content): MarkdownDocument;
}
