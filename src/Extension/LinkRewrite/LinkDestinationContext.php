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

namespace Alto\Markdown\Extension\LinkRewrite;

use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LinkDestinationContext
{
    public function __construct(
        public string $kind,
        public string $destination,
        public ?SourceRange $range,
        private string $source,
    ) {}

    /**
     * Exact source bytes for the destination-bearing node.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * @internal
     */
    public function withDestination(string $destination): self
    {
        return new self($this->kind, $destination, $this->range, $this->source);
    }
}
