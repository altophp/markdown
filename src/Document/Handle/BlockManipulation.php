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
use Alto\Markdown\Operation\BlockWrapper;
use Alto\Markdown\Operation\InsertBlockOperation;
use Alto\Markdown\Operation\InsertionPointOperation;
use Alto\Markdown\Operation\MoveBlockOperation;
use Alto\Markdown\Operation\ReplaceBlockOperation;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Query\Collection;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlockManipulation
{
    public function __construct(private ParsedDocumentModel $model) {}

    public function move(NodeId $target, NodeId $anchor, BlockInsertPosition $position): NodeId
    {
        $this->assertSubject($target);

        if ($this->model->blocksAreAtPosition($target, $anchor, $position)) {
            return $target;
        }

        $targetRange = $this->model->blockMoveDeletionRange($target);
        $insertionRange = $this->model->blockInsertionRange($anchor, $position);
        $markdown = $this->model->blockMarkdown($target);
        $replacement = new BlockSourceFormatting($this->model)->exactInsertionReplacement($insertionRange, $markdown);
        $affectedRange = new SourceRange(
            \min($targetRange->startOffset, $insertionRange->startOffset),
            \max($targetRange->endOffset, $insertionRange->endOffset),
        );
        $patches = !$this->model->blockIsSourceBacked($target) || $this->requiresInsertionFallback($insertionRange)
            ? []
            : [
                new SourcePatch($targetRange, '', $targetRange, 'remove moved block from original position'),
                new SourcePatch($insertionRange, $replacement, $insertionRange, 'insert moved block at destination'),
            ];
        if ($this->model->wouldCreateFrontMatterAfterMove($target, $anchor, $position)) {
            throw new InvalidMarkdownArgumentException('Block move must not create front matter at the document start.');
        }

        $operation = new MoveBlockOperation(
            $target,
            $anchor,
            $position,
            $affectedRange,
            $patches,
            $insertionRange,
        );
        $operation->apply($this->model);
        $this->model->journal()->record($operation, $affectedRange);

        return $this->model->currentNodeId($target->ordinal);
    }

    public function copy(NodeId $target, NodeId $anchor, BlockInsertPosition $position): Block
    {
        $this->assertSubject($target);
        $markdown = $this->model->blockMarkdown($target);
        $inserted = $this->insertExact(
            $anchor,
            $markdown,
            $position,
            !$this->model->blockIsSourceBacked($target),
        );

        return $this->singleBlock($inserted, 'Cloning one block');
    }

    /**
     * @return Collection<Block>
     */
    public function replace(NodeId $target, MarkdownFragment|string $content): Collection
    {
        $this->assertSubject($target);
        $sourceFormatting = new BlockSourceFormatting($this->model);
        $markdown = $sourceFormatting->normalize(
            $content instanceof MarkdownFragment ? $content->toMarkdown() : $content,
        );
        $range = '' === $markdown
            ? $this->model->blockMoveDeletionRange($target)
            : $this->model->blockSourceRange($target);

        $replacement = $sourceFormatting->replacement($range, $markdown);

        if ($this->model->wouldCreateFrontMatterAfterReplacement($target, $markdown)) {
            throw new InvalidMarkdownArgumentException('Block replacement must not create front matter at the document start.');
        }

        $operation = new ReplaceBlockOperation(
            $target,
            $markdown,
            $range,
            $replacement,
            requiresFallback: !$this->model->blockIsSourceBacked($target),
        );
        $inserted = $operation->applyAndReturn($this->model);
        $this->model->journal()->record($operation, $range);

        return $this->collection($inserted);
    }

    public function wrap(NodeId $target, BlockWrapper $wrapper): Block
    {
        $this->assertSubject($target);
        $range = $this->model->blockSourceRange($target);
        $markdown = $wrapper->apply($this->model->blockMarkdown($target));
        $operation = new ReplaceBlockOperation(
            $target,
            $markdown,
            $range,
            $markdown,
            'wrap block',
            !$this->model->blockIsSourceBacked($target),
        );
        $inserted = $operation->applyAndReturn($this->model);
        $this->model->journal()->record($operation, $range);

        return $this->singleBlock($inserted, 'A safe block wrapper');
    }

    /**
     * @return list<NodeId>
     */
    private function insertExact(
        NodeId $anchor,
        string $markdown,
        BlockInsertPosition $position,
        bool $requiresFallback = false,
    ): array {
        $range = $this->model->blockInsertionRange($anchor, $position);
        $replacement = new BlockSourceFormatting($this->model)->exactInsertionReplacement($range, $markdown);

        if ($this->model->wouldCreateFrontMatterAfterInsertion($anchor, $markdown, $position)) {
            throw new InvalidMarkdownArgumentException('Block clone must not create front matter at the document start.');
        }

        $requiresFallback = $requiresFallback || $this->requiresInsertionFallback($range);

        $operation = new InsertBlockOperation($anchor, $markdown, $position, $range, $replacement, $requiresFallback);
        $inserted = $operation->applyAndReturn($this->model);

        if ([] !== $inserted) {
            $this->model->journal()->record($operation, $range);
        }

        return $inserted;
    }

    private function requiresInsertionFallback(SourceRange $range): bool
    {
        foreach ($this->model->journal()->operations() as $previous) {
            if ($previous instanceof InsertionPointOperation && $previous->insertsAt($range)) {
                return true;
            }
        }

        return false;
    }

    private function assertSubject(NodeId $target): void
    {
        $this->model->blockSourceRange($target);

        if ($this->model->isFrontMatterBlock($target)) {
            throw new InvalidMarkdownArgumentException('Use the dedicated front matter API instead of general block manipulation.');
        }
    }

    /**
     * @param list<NodeId> $ids
     *
     * @return Collection<Block>
     */
    private function collection(array $ids): Collection
    {
        return new LazyCollection(function () use ($ids): iterable {
            foreach ($ids as $id) {
                yield $this->block($id);
            }
        });
    }

    /**
     * @param list<NodeId> $ids
     */
    private function singleBlock(array $ids, string $operation): Block
    {
        if (1 !== \count($ids)) {
            throw new \LogicException($operation . ' must produce exactly one top-level block.');
        }

        return $this->block($ids[0]);
    }

    private function block(NodeId $id): Block
    {
        $handle = $this->model->node($id);

        if (!$handle instanceof Block) {
            throw new \LogicException('General block manipulation produced a non-block handle.');
        }

        return $handle;
    }
}
