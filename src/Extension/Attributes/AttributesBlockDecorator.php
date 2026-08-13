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

use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Document\PlannedHtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class AttributesBlockDecorator implements PlannedHtmlNodeDecorator
{
    public function __construct(private AttributesHtmlInjector $injector = new AttributesHtmlInjector()) {}

    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        unset($context);

        return $html;
    }

    public function decorateWithPlan(
        HtmlNodeOutputContext $context,
        string $html,
        DocumentRenderPlan $plan,
    ): string {
        $catalog = $plan->projection(AttributesCatalog::class);
        $ordinal = $context->ordinal();

        if (!$catalog instanceof AttributesCatalog || null === $ordinal) {
            return $html;
        }

        $attributes = $catalog->block($ordinal);
        if ([] === $attributes) {
            return $html;
        }

        return $this->injector->first(
            $html,
            $attributes,
            static function (string $name, string $value) use ($context): ?string {
                $escaped = \in_array($name, ['href', 'src'], true)
                    ? $context->escapeUrl($value)
                    : $context->escapeAttribute($value);

                return '' !== $value && '' === $escaped ? null : $escaped;
            },
        );
    }
}
