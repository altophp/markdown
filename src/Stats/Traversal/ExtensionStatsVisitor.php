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

namespace Alto\Markdown\Stats\Traversal;

use Alto\Markdown\Extension\Stats\StatsBlock;
use Alto\Markdown\Extension\Stats\StatsContext;
use Alto\Markdown\Extension\Stats\StatsInline;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;
use Alto\Markdown\Traversal\TraversalVisitor;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ExtensionStatsVisitor implements TraversalVisitor
{
    /**
     * @var list<StatsBlock>
     */
    private array $blocks = [];

    /**
     * @var list<StatsInline>
     */
    private array $inlines = [];

    public function __construct(
        private readonly StatsVisitor $core,
        private readonly bool $captureInlines,
    ) {}

    public function enterBlock(BlockEvent $event): void
    {
        ++Instrumentation::$extensionStatsEvents;
        $this->blocks[] = new StatsBlock($event->kind->name, $event->range, $event->depth);
        $this->core->enterBlock($event);
    }

    public function leaveBlock(BlockEvent $event): void
    {
        $this->core->leaveBlock($event);
    }

    public function inline(InlineEvent $event): void
    {
        if ($this->captureInlines) {
            ++Instrumentation::$extensionStatsEvents;
            $this->inlines[] = new StatsInline($event->kind, $event->range);
        }

        $this->core->inline($event);
    }

    public function context(string $source): StatsContext
    {
        return new StatsContext($source, $this->blocks, $this->inlines);
    }
}
