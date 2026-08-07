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

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface MarkdownFragmentBuilder
{
    public function paragraph(string $text): self;

    public function codeBlock(?string $language, string $code): self;

    public function raw(string $markdown): self;

    public function toFragment(): MarkdownFragment;
}
