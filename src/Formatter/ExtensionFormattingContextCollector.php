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

namespace Alto\Markdown\Formatter;

use Alto\Markdown\Extension\Formatter\FormatterBlock;
use Alto\Markdown\Extension\Formatter\FormatterInline;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;
use Alto\Markdown\Traversal\TraversalVisitor;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ExtensionFormattingContextCollector implements TraversalVisitor
{
    /**
     * @var list<FormatterBlock>
     */
    private array $blocks = [];

    /**
     * @var list<FormatterInline>
     */
    private array $inlines = [];

    public function __construct()
    {
        ++Instrumentation::$extensionFormatterContexts;
    }

    public function enterBlock(BlockEvent $event): void
    {
        $this->blocks[] = new FormatterBlock($event->kind->name, $event->range, $event->depth);
    }

    public function leaveBlock(BlockEvent $event): void
    {
    }

    public function inline(InlineEvent $event): void
    {
        $this->inlines[] = new FormatterInline($event->kind, $event->range);
    }

    /**
     * @return list<FormatterBlock>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * @return list<FormatterInline>
     */
    public function inlines(): array
    {
        return $this->inlines;
    }
}
