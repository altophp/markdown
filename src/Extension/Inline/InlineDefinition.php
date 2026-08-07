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

namespace Alto\Markdown\Extension\Inline;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineDefinition
{
    public function __construct(
        public string $kind,
        public InlineParser $parser,
        public HtmlInlineRenderer $html,
        public MarkdownInlinePrinter $markdown,
        public ?InlineLinkSemantics $link = null,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $kind)) {
            throw new InvalidExtensionException(\sprintf('Inline kind name "%s" must start with a lowercase letter and contain only lowercase letters, digits, and hyphens.', $kind));
        }
    }
}
