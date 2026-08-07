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

namespace Alto\Markdown;

use Alto\Markdown\Builder\MarkdownBuilder;
use Alto\Markdown\Builder\MarkdownFragmentBuilder;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Profile\Profile;
use Alto\Markdown\Render\RenderOptions;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface MarkdownFactory
{
    public function profile(): Profile;

    public function with(ExtensionInterface ...$extensions): self;

    public function open(string $path, ?ParseOptions $options = null): MarkdownFile;

    public function fromString(string $source, ?ParseOptions $options = null): MarkdownDocument;

    public function toHtml(string $source, ?ParseOptions $parseOptions = null, ?RenderOptions $renderOptions = null): string;

    public function toInlineHtml(string $source, ?ParseOptions $parseOptions = null, ?RenderOptions $renderOptions = null): string;

    public function builder(): MarkdownBuilder;

    public function fragment(): MarkdownFragmentBuilder;
}
