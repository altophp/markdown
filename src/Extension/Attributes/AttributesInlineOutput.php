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

namespace Alto\Markdown\Extension\Attributes;

use Alto\Markdown\Extension\Inline\AccumulatingHtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class AttributesInlineOutput implements AccumulatingHtmlInlineRenderer, MarkdownInlinePrinter
{
    public function __construct(
        private AttributeListParser $parser,
        private AttributesHtmlInjector $injector = new AttributesHtmlInjector(),
    ) {}

    public function render(HtmlInlineOutputContext $context): string
    {
        return $context->escapeText($context->source());
    }

    public function accumulate(HtmlInlineOutputContext $context, string $html): string
    {
        $attributes = $this->parser->parseWhole($context->source())?->attributes;
        if (null === $attributes) {
            return $html . $context->escapeText($context->source());
        }

        $injected = $this->injector->last(
            $html,
            $attributes,
            static function (string $name, string $value) use ($context): ?string {
                $escaped = \in_array($name, ['href', 'src'], true)
                    ? $context->escapeUrl($value)
                    : $context->escapeAttribute($value);

                return '' !== $value && '' === $escaped ? null : $escaped;
            },
        );

        return $injected ?? $html . $context->escapeText($context->source());
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return $context->source();
    }
}
