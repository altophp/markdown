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
final class NoSkippedHeadingLevelsRule implements BlockRule
{
    private ?int $previousLevel = null;

    public function id(): string
    {
        return 'no-skipped-heading-levels';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        if (!$event->model instanceof ParsedDocumentModel || !$this->isHeading($event)) {
            return;
        }

        $level = $event->model->headingLevel($event->id->ordinal);
        $previous = $this->previousLevel;
        $this->previousLevel = $level;

        if (null === $previous || $level <= $previous + 1) {
            return;
        }

        yield new LintProblem(
            $this->id(),
            \sprintf('Heading level jumps from %d to %d.', $previous, $level),
            $event->range,
            severity: $context->config->severityFor($this->id()),
        );
    }

    private function isHeading(BlockEvent $event): bool
    {
        return 'atx-heading' === $event->kind->name || 'setext-heading' === $event->kind->name;
    }
}
