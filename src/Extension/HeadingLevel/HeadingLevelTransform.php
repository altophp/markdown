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

namespace Alto\Markdown\Extension\HeadingLevel;

use Alto\Markdown\Extension\Document\DocumentTransform;
use Alto\Markdown\Extension\Document\DocumentTransformContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HeadingLevelTransform implements DocumentTransform
{
    public function __construct(private HeadingLevelPolicy $policy) {}

    public function transform(DocumentTransformContext $context): void
    {
        foreach ($context->headings() as $heading) {
            $current = $context->renderedHeadingLevel($heading);
            $resolved = $this->policy->resolve($current);

            if (null === $resolved || $resolved === $current) {
                continue;
            }

            $context->overrideHeadingLevel($heading, $resolved);
        }
    }
}
