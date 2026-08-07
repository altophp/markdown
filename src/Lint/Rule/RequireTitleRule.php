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
use Alto\Markdown\Lint\DocumentRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class RequireTitleRule implements DocumentRule
{
    public function id(): string
    {
        return 'require-title';
    }

    public function checkDocument(DocumentModel $model, RuleContext $context): iterable
    {
        if (!$model instanceof ParsedDocumentModel) {
            return;
        }

        $first = $model->firstChildOrdinal($model->rootNodeId()->ordinal);

        // Front matter is metadata, not document content, so the title is
        // still the first block: the one after it.
        if (ParseTape::NONE !== $first && $first === $model->frontMatterOrdinal()) {
            $first = $model->nextSiblingOrdinal($first);
        }

        if (ParseTape::NONE !== $first) {
            $id = $model->currentNodeId($first);

            if (
                ('atx-heading' === $model->nodeKind($id)->name || 'setext-heading' === $model->nodeKind($id)->name)
                && 1 === $model->headingLevel($first)
            ) {
                return;
            }

            $range = $model->range($id);
        } else {
            $range = new SourceRange(0, 0);
        }

        yield new LintProblem(
            $this->id(),
            'Document must start with a top-level heading.',
            $range,
            severity: $context->config->severityFor($this->id()),
        );
    }
}
