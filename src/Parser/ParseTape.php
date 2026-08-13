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

namespace Alto\Markdown\Parser;

use Alto\Markdown\Exception\SourcePositionException;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Extension\Inline\InlineNode;

/**
 * Columnar block tape: the single storage for parsed block structure.
 *
 * One slot per block, held as parallel int arrays (struct of arrays) rather
 * than one object per block. A slot records its kind id, parent ordinal,
 * first-child and next-sibling ordinals, start and end byte offsets into the
 * original input, generation stamp, and a flags bitfield. Non-int data (for
 * example a fence info string) lives in a side table keyed by ordinal.
 *
 * Slots are append-only in this step. allocate() hands out the next ordinal,
 * one past the highest ever allocated, and no slot is ever removed or
 * renumbered. Links, the end offset, and flags are set after the fact, because
 * a block closes later than it opens. The same call sequence always produces
 * the same tape.
 *
 * The sentinel for "no link", and for an offset that is not yet known, is
 * self::NONE (-1).
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ParseTape implements ReadOnlyParseTape
{
    public const int NONE = -1;

    /**
     * @var array<int, int>
     */
    private array $kind = [];

    /**
     * @var array<int, int>
     */
    private array $parent = [];

    /**
     * @var array<int, int>
     */
    private array $firstChild = [];

    /**
     * @var array<int, int>
     */
    private array $nextSibling = [];

    /**
     * @var array<int, int>
     */
    private array $startOffset = [];

    /**
     * @var array<int, int>
     */
    private array $endOffset = [];

    /**
     * @var array<int, int>
     */
    private array $generation = [];

    /**
     * @var array<int, int>
     */
    private array $flags = [];

    /**
     * @var array<int, string>
     */
    private array $payload = [];

    /**
     * @var array<int, BlockState>
     */
    private array $extensionBlockState = [];

    /**
     * @var array<int, InlineNode>
     */
    private array $extensionInlineNode = [];

    /**
     * Append a slot and return its ordinal (one past the highest ever
     * allocated). First-child and next-sibling default to NONE, the end offset
     * to NONE (the block is still open), and flags to 0.
     */
    public function allocate(int $kindId, int $parentOrdinal, int $startOffset, int $generation): int
    {
        if (self::NONE !== $parentOrdinal && !isset($this->kind[$parentOrdinal])) {
            $this->throwLinkTarget($parentOrdinal, 'parent ordinal');
        }

        // Inlined append: this runs once per block, so the extra frame was a
        // measurable share of the per-block fixed cost (PD.1).
        $ordinal = \count($this->kind);

        $this->kind[] = $kindId;
        $this->parent[] = $parentOrdinal;
        $this->firstChild[] = self::NONE;
        $this->nextSibling[] = self::NONE;
        $this->startOffset[] = $startOffset;
        $this->endOffset[] = self::NONE;
        $this->generation[] = $generation;
        $this->flags[] = 0;

        return $ordinal;
    }

    public function allocateClosed(int $kindId, int $parentOrdinal, int $startOffset, int $endOffset, int $generation, int $flags = 0, ?string $payload = null): int
    {
        if (self::NONE !== $parentOrdinal && !isset($this->kind[$parentOrdinal])) {
            $this->throwLinkTarget($parentOrdinal, 'parent ordinal');
        }

        return $this->appendSlot($kindId, $parentOrdinal, $startOffset, $endOffset, $generation, $flags, $payload);
    }

    public function appendChild(int $kindId, int $parentOrdinal, int $previousSibling, int $startOffset, int $endOffset, int $generation, int $flags = 0, ?string $payload = null): int
    {
        if (!isset($this->kind[$parentOrdinal])) {
            $this->throwOrdinal($parentOrdinal);
        }
        if (self::NONE !== $previousSibling && !isset($this->kind[$previousSibling])) {
            $this->throwLinkTarget($previousSibling, 'previous sibling ordinal');
        }

        $ordinal = $this->appendSlot($kindId, $parentOrdinal, $startOffset, $endOffset, $generation, $flags, $payload);

        if (self::NONE === $previousSibling) {
            $this->firstChild[$parentOrdinal] = $ordinal;
        } else {
            $this->nextSibling[$previousSibling] = $ordinal;
        }

        return $ordinal;
    }

    private function appendSlot(int $kindId, int $parentOrdinal, int $startOffset, int $endOffset, int $generation, int $flags, ?string $payload): int
    {
        $ordinal = \count($this->kind);

        $this->kind[] = $kindId;
        $this->parent[] = $parentOrdinal;
        $this->firstChild[] = self::NONE;
        $this->nextSibling[] = self::NONE;
        $this->startOffset[] = $startOffset;
        $this->endOffset[] = $endOffset;
        $this->generation[] = $generation;
        $this->flags[] = $flags;

        if (null !== $payload) {
            $this->payload[$ordinal] = $payload;
        }

        return $ordinal;
    }

    /**
     * Number of slots ever allocated. Because slots are append-only, this is
     * also one past the highest ordinal.
     */
    public function count(): int
    {
        return \count($this->kind);
    }

    public function linkFirstChild(int $parentOrdinal, int $childOrdinal): void
    {
        if (!isset($this->kind[$parentOrdinal])) {
            $this->throwOrdinal($parentOrdinal);
        }
        if (self::NONE !== $childOrdinal && !isset($this->kind[$childOrdinal])) {
            $this->throwLinkTarget($childOrdinal, 'child ordinal');
        }

        $this->firstChild[$parentOrdinal] = $childOrdinal;
    }

    public function linkNextSibling(int $ordinal, int $siblingOrdinal): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }
        if (self::NONE !== $siblingOrdinal && !isset($this->kind[$siblingOrdinal])) {
            $this->throwLinkTarget($siblingOrdinal, 'sibling ordinal');
        }

        $this->nextSibling[$ordinal] = $siblingOrdinal;
    }

    public function setEndOffset(int $ordinal, int $endOffset): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        $this->endOffset[$ordinal] = $endOffset;
    }

    public function setParentOrdinal(int $ordinal, int $parentOrdinal): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }
        if (self::NONE !== $parentOrdinal && !isset($this->kind[$parentOrdinal])) {
            $this->throwLinkTarget($parentOrdinal, 'parent ordinal');
        }

        $this->parent[$ordinal] = $parentOrdinal;
    }

    public function setFlags(int $ordinal, int $flags): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        $this->flags[$ordinal] = $flags;
    }

    public function bumpGeneration(int $ordinal): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        ++$this->generation[$ordinal];
    }

    /**
     * Stamps a slot with an explicit generation. A document workspace uses this
     * to record the document-wide revision at which the slot last changed,
     * rather than a per-slot count of edits.
     */
    public function setGeneration(int $ordinal, int $generation): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        $this->generation[$ordinal] = $generation;
    }

    public function setKindId(int $ordinal, int $kindId): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        $this->kind[$ordinal] = $kindId;
    }

    public function addFlags(int $ordinal, int $flags): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        $this->flags[$ordinal] |= $flags;
    }

    public function setPayload(int $ordinal, string $payload): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        $this->payload[$ordinal] = $payload;
    }

    public function setExtensionBlockState(int $ordinal, BlockState $state): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        $this->extensionBlockState[$ordinal] = $state;
    }

    public function extensionBlockState(int $ordinal): BlockState
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->extensionBlockState[$ordinal] ?? new BlockState();
    }

    public function setExtensionInlineNode(int $ordinal, InlineNode $node): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        $this->extensionInlineNode[$ordinal] = $node;
    }

    public function extensionInlineNode(int $ordinal): InlineNode
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->extensionInlineNode[$ordinal]
            ?? throw new SourcePositionException(\sprintf('Inline ordinal %d has no extension node.', $ordinal));
    }

    public function hasExtensionInlineNode(int $ordinal): bool
    {
        return isset($this->extensionInlineNode[$ordinal]);
    }

    /**
     * Appends one encoded part without reading and replacing the complete
     * payload at the call site. This keeps growing block payloads append-only
     * while their block remains open.
     */
    public function appendPayloadPart(int $ordinal, string $part, string $separator = ''): void
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        if (!isset($this->payload[$ordinal])) {
            $this->payload[$ordinal] = $part;

            return;
        }

        if ('' !== $separator && '' !== $this->payload[$ordinal]) {
            $this->payload[$ordinal] .= $separator;
        }

        $this->payload[$ordinal] .= $part;
    }

    public function copySubtreeFrom(ParseTape $source, int $sourceOrdinal, int $parentOrdinal, int $startOffset, int $endOffset, int $generation): int
    {
        if (!isset($source->kind[$sourceOrdinal])) {
            $source->throwOrdinal($sourceOrdinal);
        }

        if (self::NONE !== $parentOrdinal && !isset($this->kind[$parentOrdinal])) {
            $this->throwLinkTarget($parentOrdinal, 'parent ordinal');
        }

        $ordinal = $this->allocate($source->kind[$sourceOrdinal], $parentOrdinal, $startOffset, $generation);
        $this->setEndOffset($ordinal, $endOffset);
        $this->setFlags($ordinal, $source->flags[$sourceOrdinal]);

        if (isset($source->payload[$sourceOrdinal])) {
            $this->setPayload($ordinal, $source->payload[$sourceOrdinal]);
        }
        if (isset($source->extensionBlockState[$sourceOrdinal])) {
            $this->setExtensionBlockState($ordinal, $source->extensionBlockState[$sourceOrdinal]);
        }
        if (isset($source->extensionInlineNode[$sourceOrdinal])) {
            $this->setExtensionInlineNode($ordinal, $source->extensionInlineNode[$sourceOrdinal]);
        }

        $previous = self::NONE;
        $child = $source->firstChild[$sourceOrdinal];

        while (self::NONE !== $child) {
            $copied = $this->copySubtreeFrom($source, $child, $ordinal, $startOffset, $endOffset, $generation);

            if (self::NONE === $previous) {
                $this->linkFirstChild($ordinal, $copied);
            } else {
                $this->linkNextSibling($previous, $copied);
            }

            $previous = $copied;
            $child = $source->nextSibling[$child];
        }

        return $ordinal;
    }

    public function columns(): ParseTapeColumns
    {
        return new ParseTapeColumns(
            $this->kind,
            $this->parent,
            $this->firstChild,
            $this->nextSibling,
            $this->startOffset,
            $this->endOffset,
            $this->generation,
            $this->flags,
            $this->payload,
            $this->extensionInlineNode,
        );
    }

    public function kindId(int $ordinal): int
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->kind[$ordinal];
    }

    public function parentOrdinal(int $ordinal): int
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->parent[$ordinal];
    }

    public function firstChildOrdinal(int $ordinal): int
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->firstChild[$ordinal];
    }

    public function nextSiblingOrdinal(int $ordinal): int
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->nextSibling[$ordinal];
    }

    public function startOffset(int $ordinal): int
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->startOffset[$ordinal];
    }

    public function endOffset(int $ordinal): int
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->endOffset[$ordinal];
    }

    public function generation(int $ordinal): int
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->generation[$ordinal];
    }

    public function flags(int $ordinal): int
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->flags[$ordinal];
    }

    public function payload(int $ordinal): ?string
    {
        if (!isset($this->kind[$ordinal])) {
            $this->throwOrdinal($ordinal);
        }

        return $this->payload[$ordinal] ?? null;
    }

    /**
     * @throws SourcePositionException always; callers inline the isset guard
     *                                 so the hot path never enters a frame
     */
    private function throwOrdinal(int $ordinal): never
    {
        throw new SourcePositionException(\sprintf('Block ordinal %d is out of range [0, %d).', $ordinal, \count($this->kind)));
    }

    /**
     * @throws SourcePositionException always; callers inline the isset guard
     *                                 so the hot path never enters a frame
     */
    private function throwLinkTarget(int $ordinal, string $label): never
    {
        throw new SourcePositionException(\sprintf('The %s %d is out of range [0, %d).', $label, $ordinal, \count($this->kind)));
    }
}
