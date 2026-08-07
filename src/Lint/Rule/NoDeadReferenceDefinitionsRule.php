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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Lint\AfterTraversalRule;
use Alto\Markdown\Lint\InlineRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Traversal\InlineEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class NoDeadReferenceDefinitionsRule implements InlineRule, AfterTraversalRule
{
    public function id(): string
    {
        return 'no-dead-reference-definitions';
    }

    public function inline(InlineEvent $event, RuleContext $context): iterable
    {
        return [];
    }

    public function afterTraversal(DocumentModel $model, RuleContext $context): iterable
    {
        if (!$model instanceof ParsedDocumentModel) {
            return;
        }

        $unused = array_flip($model->unusedReferenceLabels());

        if ([] === $unused) {
            return;
        }

        foreach ($model->queryHandles([$model->coreNodeKind('link-reference-definition')]) as $handle) {
            $label = $model->referenceDefinitionLabel($handle->id()->ordinal);

            if (null === $label || !isset($unused[$label])) {
                continue;
            }

            yield new LintProblem(
                $this->id(),
                \sprintf('Link reference definition "%s" is not used.', $label),
                $handle->range(),
                severity: $context->config->severityFor($this->id()),
            );
        }
    }
}
