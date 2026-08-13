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

namespace Alto\Markdown\Lint\Engine;

use Alto\Markdown\Extension\Lint\LintBlock;
use Alto\Markdown\Extension\Lint\LintInline;
use Alto\Markdown\Lint\BlockRule;
use Alto\Markdown\Lint\InlineRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;
use Alto\Markdown\Traversal\TraversalVisitor;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class RuleEngineVisitor implements TraversalVisitor
{
    /**
     * @var list<LintProblem>
     */
    private array $problems = [];

    /**
     * @var list<LintBlock>
     */
    private array $blocks = [];

    /**
     * @var list<LintInline>
     */
    private array $inlines = [];

    /**
     * @param list<BlockRule>  $blockRules
     * @param list<InlineRule> $inlineRules
     */
    public function __construct(
        private readonly array $blockRules,
        private readonly array $inlineRules,
        private readonly RuleContext $context,
        private readonly bool $capturePublicContext = false,
    ) {}

    public function enterBlock(BlockEvent $event): void
    {
        if ($this->capturePublicContext) {
            $this->blocks[] = new LintBlock($event->kind->name, $event->range, $event->depth);
        }

        foreach ($this->blockRules as $rule) {
            array_push($this->problems, ...$rule->enterBlock($event, $this->context));
        }
    }

    public function leaveBlock(BlockEvent $event): void {}

    public function inline(InlineEvent $event): void
    {
        if ($this->capturePublicContext) {
            $this->inlines[] = new LintInline($event->kind, $event->range);
        }

        foreach ($this->inlineRules as $rule) {
            array_push($this->problems, ...$rule->inline($event, $this->context));
        }
    }

    /**
     * @return list<LintProblem>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * @return list<LintBlock>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * @return list<LintInline>
     */
    public function inlines(): array
    {
        return $this->inlines;
    }
}
