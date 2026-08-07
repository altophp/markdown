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
use Alto\Markdown\Lint\AfterTraversalRule;
use Alto\Markdown\Lint\BlockRule;
use Alto\Markdown\Lint\InlineRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Operation\TrailingSpaceOperationFactory;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class NoTrailingSpacesRule implements AfterTraversalRule, BlockRule, InlineRule
{
    /**
     * @var list<BlockEvent>
     */
    private array $events = [];

    /**
     * @var list<InlineEvent>
     */
    private array $inlineEvents = [];

    public function id(): string
    {
        return 'no-trailing-spaces';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        $this->events[] = $event;

        return [];
    }

    public function inline(InlineEvent $event, RuleContext $context): iterable
    {
        if (\in_array($event->kind, ['code-span', 'html-inline'], true)) {
            $this->inlineEvents[] = $event;
        }

        return [];
    }

    public function afterTraversal(DocumentModel $model, RuleContext $context): iterable
    {
        $events = $this->events;
        $inlineEvents = $this->inlineEvents;
        $this->events = [];
        $this->inlineEvents = [];

        foreach (new TrailingSpaceOperationFactory()->create($model, $events, $inlineEvents, 'remove trailing spaces') as $operation) {
            $patch = $operation->toPatch($model);

            yield new LintProblem(
                $this->id(),
                'Line must not contain trailing spaces.',
                $patch->range,
                $operation,
                severity: $context->config->severityFor($this->id()),
            );
        }
    }
}
