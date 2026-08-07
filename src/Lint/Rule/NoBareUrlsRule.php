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

use Alto\Markdown\Lint\InlineRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Traversal\InlineEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class NoBareUrlsRule implements InlineRule
{
    public function id(): string
    {
        return 'no-bare-urls';
    }

    public function inline(InlineEvent $event, RuleContext $context): iterable
    {
        if ('autolink' !== $event->kind) {
            return;
        }

        $source = substr(
            $event->model->source()->bytes,
            $event->range->startOffset,
            $event->range->endOffset - $event->range->startOffset,
        );

        $bytes = $event->model->source()->bytes;
        $explicit = $event->range->startOffset > 0
            && '<' === $bytes[$event->range->startOffset - 1]
            && ($bytes[$event->range->endOffset] ?? '') === '>';

        if ($explicit || str_starts_with($source, '<')) {
            return;
        }

        yield new LintProblem(
            $this->id(),
            'Bare URL should be wrapped in angle brackets.',
            $event->range,
            new SourcePatchOperation(new SourcePatch($event->range, '<'.$source.'>', $event->range, 'wrap bare URL')),
            severity: $context->config->severityFor($this->id()),
        );
    }
}
