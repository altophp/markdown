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

namespace Alto\Markdown\Extension\Html;

use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Document\PlannedHtmlNodeDecorator;
use Alto\Markdown\Parser\Instrumentation;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CompiledHtmlDecoratorChain
{
    /**
     * @param list<HtmlNodeDecorator> $decorators
     */
    public function __construct(private array $decorators) {}

    public function decorate(
        HtmlNodeOutputContext $context,
        string $html,
        ?DocumentRenderPlan $plan = null,
    ): string {
        foreach ($this->decorators as $decorator) {
            ++Instrumentation::$htmlDecoratorInvocations;
            $html = $decorator instanceof PlannedHtmlNodeDecorator && null !== $plan
                ? $decorator->decorateWithPlan($context, $html, $plan)
                : $decorator->decorate($context, $html);
        }

        return $html;
    }
}
