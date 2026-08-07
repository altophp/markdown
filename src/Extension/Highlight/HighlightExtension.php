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

namespace Alto\Markdown\Extension\Highlight;

use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\InlineExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HighlightExtension implements InlineExtensionInterface
{
    public function name(): string
    {
        return 'highlight';
    }

    public function inlines(): iterable
    {
        $output = new HighlightOutput();

        yield new InlineDefinition(
            kind: 'mark',
            parser: new HighlightParser(),
            html: $output,
            markdown: $output,
        );
    }
}
