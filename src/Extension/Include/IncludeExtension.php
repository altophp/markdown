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

namespace Alto\Markdown\Extension\Include;

use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\BlockExtensionInterface;
use Alto\Markdown\Resource\ResourceResolver;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class IncludeExtension implements BlockExtensionInterface
{
    private IncludePolicy $policy;

    public function __construct(
        private ResourceResolver $resolver,
        ?IncludePolicy $policy = null,
    ) {
        $this->policy = $policy ?? new IncludePolicy();
    }

    public function name(): string
    {
        return 'include';
    }

    public function blocks(): iterable
    {
        $output = new IncludeOutput($this->policy);

        yield new BlockDefinition(
            kind: 'block',
            parser: new IncludeParser(new IncludeExpander($this->resolver, $this->policy)),
            html: $output,
            markdown: $output,
        );
    }
}
