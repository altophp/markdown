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

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;
use Alto\Markdown\Traversal\TapeDocumentTraversal;
use Alto\Markdown\Traversal\TraversalOptions;
use Alto\Markdown\Traversal\TraversalVisitor;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BlockEventCollector implements TraversalVisitor
{
    /**
     * @var list<BlockEvent>
     */
    private array $events = [];

    /**
     * @var list<InlineEvent>
     */
    private array $inlineEvents = [];

    /**
     * @return list<BlockEvent>
     */
    public static function collect(DocumentModel $model): array
    {
        $collector = new self();
        new TapeDocumentTraversal()->traverse($model, $collector);

        return $collector->events;
    }

    /**
     * @return list<InlineEvent>
     */
    public static function collectInlines(DocumentModel $model): array
    {
        $collector = new self();
        new TapeDocumentTraversal()->traverse($model, $collector, new TraversalOptions(includeInlines: true));

        return $collector->inlineEvents;
    }

    public function enterBlock(BlockEvent $event): void
    {
        $this->events[] = $event;
    }

    public function leaveBlock(BlockEvent $event): void {}

    public function inline(InlineEvent $event): void
    {
        $this->inlineEvents[] = $event;
    }
}
