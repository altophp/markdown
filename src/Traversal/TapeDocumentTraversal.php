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

namespace Alto\Markdown\Traversal;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\UnsupportedDocumentModelException;
use Alto\Markdown\Node\Id\InlineNodeId;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseTape;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class TapeDocumentTraversal implements DocumentTraversal
{
    public function traverse(
        DocumentModel $model,
        TraversalVisitor $visitor,
        ?TraversalOptions $options = null,
    ): void {
        if (!$model instanceof ParsedDocumentModel) {
            throw new UnsupportedDocumentModelException('TapeDocumentTraversal requires a parsed document model.');
        }

        ++Instrumentation::$traversals;

        $options ??= new TraversalOptions();
        $root = $options->root ?? $model->rootNodeId();
        $this->traverseOrdinal($model, $root->ordinal, 0, $visitor, $options);
    }

    private function traverseOrdinal(
        ParsedDocumentModel $model,
        int $ordinal,
        int $depth,
        TraversalVisitor $visitor,
        TraversalOptions $options,
    ): void {
        $id = $model->currentNodeId($ordinal);
        $event = new BlockEvent(
            $model,
            $id,
            $model->nodeKind($id),
            $model->range($id),
            $depth,
        );

        $visitor->enterBlock($event);

        if ($options->includeInlines) {
            foreach ($model->traversalInlineEvents($ordinal) as [$inlineOrdinal, $kind, $range]) {
                $visitor->inline(new InlineEvent(
                    $model,
                    $id,
                    new InlineNodeId($ordinal, $inlineOrdinal),
                    $kind,
                    $range,
                ));
            }
        }

        $child = $model->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $this->traverseOrdinal($model, $child, $depth + 1, $visitor, $options);
            $child = $model->nextSiblingOrdinal($child);
        }

        $visitor->leaveBlock($event);
    }
}
