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
use Alto\Markdown\Lint\BlockRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Traversal\BlockEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class RequireClosedCodeFenceRule implements BlockRule
{
    public function id(): string
    {
        return 'require-closed-code-fence';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        if ('fenced-code' !== $event->kind->name || !$event->model instanceof ParsedDocumentModel) {
            return;
        }

        if ($event->model->codeBlockFenceIsClosed($event->id->ordinal)) {
            return;
        }

        $bytes = $event->model->source()->bytes;
        $start = $event->range->startOffset;
        $marker = $bytes[$start] ?? '';

        if ('`' !== $marker && '~' !== $marker) {
            return;
        }

        $end = $start + strspn($bytes, $marker, $start);

        yield new LintProblem(
            $this->id(),
            'Fenced code block must have a closing fence.',
            new SourceRange($start, $end),
            severity: $context->config->severityFor($this->id()),
        );
    }
}
