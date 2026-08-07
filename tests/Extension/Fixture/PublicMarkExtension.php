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

namespace Alto\Markdown\Tests\Extension\Fixture;

use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\InlineExtensionInterface;

final readonly class PublicMarkExtension implements InlineExtensionInterface
{
    public function name(): string
    {
        return 'example';
    }

    public function inlines(): iterable
    {
        $output = new PublicMarkOutput();

        yield new InlineDefinition(
            kind: 'mark',
            parser: new PublicMarkParser(),
            html: $output,
            markdown: $output,
        );
    }
}
