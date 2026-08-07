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

use Alto\Markdown\MarkdownDocument;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface EnsureRunner
{
    public function section(string $title, ?int $level = null): self;

    public function apply(): MarkdownDocument;
}
