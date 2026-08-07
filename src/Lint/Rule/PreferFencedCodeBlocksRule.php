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
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Traversal\BlockEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class PreferFencedCodeBlocksRule implements BlockRule
{
    public function id(): string
    {
        return 'prefer-fenced-code-blocks';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        if ('indented-code' !== $event->kind->name) {
            return;
        }

        $fix = null;

        if ($event->model instanceof ParsedDocumentModel && $event->depth <= 1) {
            $code = $event->model->codeBlockCode($event->id->ordinal);
            $range = $event->model->codeBlockPatchRange($event->id);
            $fix = str_contains($code, '```')
                ? null
                : new SourcePatchOperation(new SourcePatch(
                    $range,
                    $this->fenced(
                        $code,
                        \in_array('require-code-block-language', $context->config->enabledRules, true),
                    ),
                    $range,
                    'convert indented code block to fenced code block',
                ));
        }

        yield new LintProblem(
            $this->id(),
            'Prefer fenced code blocks over indented code blocks.',
            $event->range,
            $fix,
            severity: $context->config->severityFor($this->id()),
        );
    }

    private function fenced(string $code, bool $withLanguage): string
    {
        return '```'.($withLanguage ? 'text' : '')."\n".rtrim($code, "\n")."\n```";
    }
}
