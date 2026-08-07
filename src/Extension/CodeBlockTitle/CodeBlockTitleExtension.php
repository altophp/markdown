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

namespace Alto\Markdown\Extension\CodeBlockTitle;

use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CodeBlockTitleExtension implements HtmlDecoratorExtensionInterface
{
    private CodeBlockTitlePolicy $policy;

    public function __construct(?CodeBlockTitlePolicy $policy = null)
    {
        $this->policy = $policy ?? new CodeBlockTitlePolicy();
    }

    public function name(): string
    {
        return 'code-block-title';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::node(
            'fenced-code',
            new CodeBlockTitleDecorator($this->policy),
        );
    }
}
