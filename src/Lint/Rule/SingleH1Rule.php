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
use Alto\Markdown\Traversal\BlockEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class SingleH1Rule implements BlockRule
{
    private bool $seenH1 = false;

    public function id(): string
    {
        return 'single-h1';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        if (!$event->model instanceof ParsedDocumentModel || !$this->isHeading($event)) {
            return;
        }

        if (1 !== $event->model->headingLevel($event->id->ordinal)) {
            return;
        }

        if (!$this->seenH1) {
            $this->seenH1 = true;

            return;
        }

        yield new LintProblem(
            $this->id(),
            'Document must contain a single top-level heading.',
            $event->range,
            severity: $context->config->severityFor($this->id()),
        );
    }

    private function isHeading(BlockEvent $event): bool
    {
        return 'atx-heading' === $event->kind->name || 'setext-heading' === $event->kind->name;
    }
}
