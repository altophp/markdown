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

namespace Alto\Markdown\Lint\Rule;

use Alto\Markdown\Lint\BlockRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Operation\FenceInfoSpacingOperationFactory;
use Alto\Markdown\Traversal\BlockEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class CodeFenceInfoSpacingRule implements BlockRule
{
    public function id(): string
    {
        return 'code-fence-info-spacing';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        $operation = new FenceInfoSpacingOperationFactory()->create(
            $event->model,
            $event,
            'normalize code fence info spacing',
        );

        if (null === $operation) {
            return;
        }

        yield new LintProblem(
            $this->id(),
            'Code fence info string must follow the marker without whitespace.',
            $operation->toPatch($event->model)->range,
            $operation,
            severity: $context->config->severityFor($this->id()),
        );
    }
}
