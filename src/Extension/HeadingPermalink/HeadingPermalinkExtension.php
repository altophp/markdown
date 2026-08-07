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

namespace Alto\Markdown\Extension\HeadingPermalink;

use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HeadingPermalinkExtension implements HtmlDecoratorExtensionInterface
{
    private HeadingPermalinkPolicy $policy;

    public function __construct(?HeadingPermalinkPolicy $policy = null)
    {
        $this->policy = $policy ?? new HeadingPermalinkPolicy();
    }

    public function name(): string
    {
        return 'heading-permalink';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::heading(
            new HeadingPermalinkDecorator($this->policy),
            -100,
        );
    }
}
