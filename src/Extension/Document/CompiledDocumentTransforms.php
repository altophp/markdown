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

namespace Alto\Markdown\Extension\Document;

use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlRenderSource;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CompiledDocumentTransforms
{
    /**
     * @var list<DocumentTransformDefinition>
     */
    private array $definitions;

    /**
     * @param list<array{DocumentTransformDefinition, int, int}> $entries
     */
    public function __construct(array $entries)
    {
        usort(
            $entries,
            static fn (array $left, array $right): int => [
                $left[0]->order,
                $left[1],
                $left[2],
            ] <=> [
                $right[0]->order,
                $right[1],
                $right[2],
            ],
        );
        $this->definitions = array_map(
            static fn (array $entry): DocumentTransformDefinition => $entry[0],
            $entries,
        );
    }

    public function plan(HtmlRenderSource $source, bool $completeDocument): DocumentRenderPlan
    {
        ++Instrumentation::$documentTransformPlans;
        $plan = new DocumentRenderPlan();
        $context = new DocumentTransformContext($source, $plan, $completeDocument);

        foreach ($this->definitions as $definition) {
            ++Instrumentation::$documentTransformFactories;
            $transform = $definition->create();
            ++Instrumentation::$documentTransformInvocations;
            $transform->transform($context);

            if ($transform instanceof DocumentProjectionTransform) {
                $plan->provide($transform->projection());
            }
        }

        return $plan;
    }
}
