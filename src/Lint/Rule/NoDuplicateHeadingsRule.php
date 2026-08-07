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
use Alto\Markdown\Parser\CaseFold;
use Alto\Markdown\Traversal\BlockEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class NoDuplicateHeadingsRule implements BlockRule
{
    /**
     * @var array<string, true>
     */
    private array $seen = [];

    public function id(): string
    {
        return 'no-duplicate-headings';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        if (!$event->model instanceof ParsedDocumentModel || !$this->isHeading($event)) {
            return;
        }

        $title = trim($event->model->plainText($event->id->ordinal));
        $key = CaseFold::fold($title);

        if (!isset($this->seen[$key])) {
            $this->seen[$key] = true;

            return;
        }

        yield new LintProblem(
            $this->id(),
            \sprintf('Duplicate heading "%s".', $title),
            $event->range,
            severity: $context->config->severityFor($this->id()),
        );
    }

    private function isHeading(BlockEvent $event): bool
    {
        return 'atx-heading' === $event->kind->name || 'setext-heading' === $event->kind->name;
    }
}
