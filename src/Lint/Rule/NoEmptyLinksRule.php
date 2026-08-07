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
use Alto\Markdown\Lint\InlineRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Traversal\InlineEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class NoEmptyLinksRule implements InlineRule
{
    public function id(): string
    {
        return 'no-empty-links';
    }

    public function inline(InlineEvent $event, RuleContext $context): iterable
    {
        if ('link' !== $event->kind && 'image' !== $event->kind) {
            return;
        }

        if (!$event->model instanceof ParsedDocumentModel) {
            return;
        }

        [$destination] = $event->model->inlinePayloadParts($event->blockId->ordinal, $event->id->inlineOrdinal);

        if ('' !== $destination) {
            return;
        }

        yield new LintProblem(
            $this->id(),
            'Link destination must not be empty.',
            $event->range,
            severity: $context->config->severityFor($this->id()),
        );
    }
}
