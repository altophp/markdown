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

namespace Alto\Markdown\Extension\ExternalLink;

use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ExternalLinkExtension implements HtmlDecoratorExtensionInterface
{
    private ExternalLinkPolicy $policy;

    public function __construct(?ExternalLinkPolicy $policy = null)
    {
        $this->policy = $policy ?? new ExternalLinkPolicy();
    }

    public function name(): string
    {
        return 'external-link';
    }

    public function htmlDecorators(): iterable
    {
        $decorator = new ExternalLinkDecorator($this->policy);

        yield HtmlDecoratorDefinition::linkLike($decorator, -100);
    }
}
