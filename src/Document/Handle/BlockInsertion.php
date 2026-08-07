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

namespace Alto\Markdown\Document\Handle;

use Alto\Markdown\Builder\MarkdownFragment;
use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Document\Query\LazyCollection;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Node\Block;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Operation\BlockInsertPosition;
use Alto\Markdown\Operation\InsertBlockOperation;
use Alto\Markdown\Operation\InsertionPointOperation;
use Alto\Markdown\Query\Collection;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlockInsertion
{
    public function __construct(private ParsedDocumentModel $model)
    {
    }

    /**
     * @return Collection<Block>
     */
    public function apply(
        NodeId $target,
        MarkdownFragment|string $content,
        BlockInsertPosition $position,
    ): Collection {
        $markdown = $content instanceof MarkdownFragment ? $content->toMarkdown() : $content;
        $sourceFormatting = new BlockSourceFormatting($this->model);
        $markdown = $sourceFormatting->normalize($markdown);

        if ('' === $markdown) {
            return $this->collection([]);
        }

        if ($this->model->wouldCreateFrontMatterAfterInsertion($target, $markdown, $position)) {
            throw new InvalidMarkdownArgumentException('Block insertion must not create front matter at the document start.');
        }

        $range = $this->model->blockInsertionRange($target, $position);
        $replacement = $sourceFormatting->insertionReplacement($range, $markdown);
        $requiresFallback = false;

        foreach ($this->model->journal()->operations() as $previous) {
            if ($previous instanceof InsertionPointOperation && $previous->insertsAt($range)) {
                $requiresFallback = true;

                break;
            }
        }

        $operation = new InsertBlockOperation($target, $markdown, $position, $range, $replacement, $requiresFallback);
        $inserted = $operation->applyAndReturn($this->model);

        if ([] === $inserted) {
            return $this->collection([]);
        }

        $this->model->journal()->record($operation, $range);
        $handles = [];

        foreach ($inserted as $id) {
            $handle = $this->model->node($id);

            if (!$handle instanceof Block) {
                throw new \LogicException('Inserted Markdown produced a non-block handle.');
            }

            $handles[] = $handle;
        }

        return $this->collection($handles);
    }

    /**
     * @param list<Block> $handles
     *
     * @return Collection<Block>
     */
    private function collection(array $handles): Collection
    {
        return new LazyCollection(static fn (): iterable => $handles);
    }
}
