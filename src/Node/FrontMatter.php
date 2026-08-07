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

use Alto\Markdown\Extension\FrontMatter\FrontMatterDecoder;

/**
 * The document's front matter block, exposed opaquely.
 *
 * Core does not decode YAML or TOML (SPEC section 11.7), so this handle
 * carries bytes and positions only. {@see NodeHandle::range()} covers the
 * whole block, both fences included, through the closing fence's line ending;
 * a decoder wants {@see content} and {@see fence}.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface FrontMatter extends Block
{
    /**
     * The whole block verbatim, both fences included.
     */
    public function text(): string;

    /**
     * The lines between the fences, verbatim and undecoded. Empty when the
     * two fences are adjacent.
     */
    public function content(): string;

    /**
     * The delimiter this block uses: "---" (conventionally YAML) or "+++"
     * (conventionally TOML). Core attaches no format semantics to either.
     */
    public function fence(): string;

    /**
     * Decode the current opaque content through an explicit application
     * decoder.
     *
     * @template T
     *
     * @param FrontMatterDecoder<T> $decoder
     *
     * @return T
     */
    public function decode(FrontMatterDecoder $decoder): mixed;

    /**
     * Replace the opaque bytes between the existing fences.
     */
    public function replaceContent(string $content): self;
}
