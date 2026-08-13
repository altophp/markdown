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

namespace Alto\Markdown\Extension\Import;

use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\BlockExtensionInterface;
use Alto\Markdown\Resource\ResourceResolver;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ImportExtension implements BlockExtensionInterface
{
    public function __construct(private ResourceResolver $resolver) {}

    public function name(): string
    {
        return 'import';
    }

    public function blocks(): iterable
    {
        $output = new ImportOutput();

        yield new BlockDefinition(
            kind: 'block',
            parser: new ImportParser($this->resolver),
            html: $output,
            markdown: $output,
        );
    }
}
