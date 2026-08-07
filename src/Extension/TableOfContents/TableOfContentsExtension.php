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

namespace Alto\Markdown\Extension\TableOfContents;

use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\BlockExtensionInterface;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;
use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TableOfContentsExtension implements BlockExtensionInterface, DocumentTransformExtensionInterface, HtmlDecoratorExtensionInterface
{
    private TableOfContentsPolicy $policy;

    public function __construct(?TableOfContentsPolicy $policy = null)
    {
        $this->policy = $policy ?? new TableOfContentsPolicy();
    }

    public function name(): string
    {
        return 'table-of-contents';
    }

    public function blocks(): iterable
    {
        $output = new TableOfContentsOutput($this->policy);

        yield new BlockDefinition(
            kind: 'block',
            parser: new TableOfContentsParser($this->policy),
            html: $output,
            markdown: $output,
        );
    }

    public function documentTransforms(): iterable
    {
        yield new DocumentTransformDefinition(
            'catalog',
            static fn (): TableOfContentsTransform => new TableOfContentsTransform(),
            \PHP_INT_MAX,
        );
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::heading(
            new TableOfContentsHeadingDecorator(),
        );
    }
}
