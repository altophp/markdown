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

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Lint\DocumentRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Operation\FinalNewlineOperationFactory;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FinalNewlineRule implements DocumentRule
{
    public function id(): string
    {
        return 'final-newline';
    }

    public function checkDocument(DocumentModel $model, RuleContext $context): iterable
    {
        $operation = new FinalNewlineOperationFactory()->create($model, 'append final newline');

        if (null === $operation) {
            return;
        }

        $range = $operation->toPatch($model)->range;

        yield new LintProblem(
            $this->id(),
            'Document must end with a newline.',
            $range,
            $operation,
            severity: $context->config->severityFor($this->id()),
        );
    }
}
