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

namespace Alto\Markdown\Extension\Embed;

use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\BlockExtensionInterface;
use Alto\Markdown\Resource\ResourceResolver;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class EmbedExtension implements BlockExtensionInterface
{
    public function __construct(
        private ResourceResolver $resolver,
        private EmbedPolicy $policy,
    ) {
    }

    public function name(): string
    {
        return 'embed';
    }

    public function blocks(): iterable
    {
        $output = new EmbedOutput($this->policy);

        yield new BlockDefinition(
            kind: 'block',
            parser: new EmbedParser($this->resolver, $this->policy),
            html: $output,
            markdown: $output,
        );
    }
}
