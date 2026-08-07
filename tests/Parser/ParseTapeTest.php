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

namespace Alto\Markdown\Tests\Parser;

use Alto\Markdown\Exception\SourcePositionException;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParseTapeTest extends TestCase
{
    public function testNoneSentinelIsMinusOne(): void
    {
        self::assertSame(-1, ParseTape::NONE);
    }

    public function testEmptyTapeHasNoSlots(): void
    {
        self::assertSame(0, (new ParseTape())->count());
    }

    public function testAllocateReturnsSequentialOrdinals(): void
    {
        $tape = new ParseTape();

        self::assertSame(0, $tape->allocate(0, ParseTape::NONE, 0, 0));
        self::assertSame(1, $tape->allocate(0, 0, 4, 0));
        self::assertSame(2, $tape->allocate(0, 0, 8, 0));
        self::assertSame(3, $tape->count());
    }

    public function testAllocateInitializesColumns(): void
    {
        $tape = new ParseTape();
        $ordinal = $tape->allocate(kindId: 7, parentOrdinal: ParseTape::NONE, startOffset: 3, generation: 2);

        self::assertSame(7, $tape->kindId($ordinal));
        self::assertSame(ParseTape::NONE, $tape->parentOrdinal($ordinal));
        self::assertSame(ParseTape::NONE, $tape->firstChildOrdinal($ordinal));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($ordinal));
        self::assertSame(3, $tape->startOffset($ordinal));
        self::assertSame(ParseTape::NONE, $tape->endOffset($ordinal));
        self::assertSame(2, $tape->generation($ordinal));
        self::assertSame(0, $tape->flags($ordinal));
        self::assertNull($tape->payload($ordinal));
    }

    public function testLinkFirstChildAndNextSibling(): void
    {
        $tape = new ParseTape();
        $root = $tape->allocate(0, ParseTape::NONE, 0, 0);
        $a = $tape->allocate(1, $root, 0, 0);
        $b = $tape->allocate(2, $root, 5, 0);

        $tape->linkFirstChild($root, $a);
        $tape->linkNextSibling($a, $b);

        self::assertSame($a, $tape->firstChildOrdinal($root));
        self::assertSame($b, $tape->nextSiblingOrdinal($a));
        self::assertSame(ParseTape::NONE, $tape->firstChildOrdinal($a));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($b));
    }

    public function testAllocateClosedInitializesCompleteSlot(): void
    {
        $tape = new ParseTape();
        $root = $tape->allocate(0, ParseTape::NONE, 0, 0);
        $node = $tape->allocateClosed(7, $root, 3, 12, 2, 5, 'payload');

        self::assertSame(7, $tape->kindId($node));
        self::assertSame($root, $tape->parentOrdinal($node));
        self::assertSame(3, $tape->startOffset($node));
        self::assertSame(12, $tape->endOffset($node));
        self::assertSame(2, $tape->generation($node));
        self::assertSame(5, $tape->flags($node));
        self::assertSame('payload', $tape->payload($node));
    }

    public function testAppendChildInitializesAndLinksCompleteSlots(): void
    {
        $tape = new ParseTape();
        $root = $tape->allocate(0, ParseTape::NONE, 0, 0);
        $first = $tape->appendChild(1, $root, ParseTape::NONE, 0, 4, 0);
        $second = $tape->appendChild(2, $root, $first, 5, 9, 0, payload: 'value');

        self::assertSame($first, $tape->firstChildOrdinal($root));
        self::assertSame($second, $tape->nextSiblingOrdinal($first));
        self::assertSame(9, $tape->endOffset($second));
        self::assertSame('value', $tape->payload($second));
    }

    public function testSetEndOffsetAfterAllocation(): void
    {
        $tape = new ParseTape();
        $ordinal = $tape->allocate(0, ParseTape::NONE, 0, 0);

        self::assertSame(ParseTape::NONE, $tape->endOffset($ordinal));

        $tape->setEndOffset($ordinal, 42);

        self::assertSame(42, $tape->endOffset($ordinal));
    }

    public function testSetFlagsReplacesValue(): void
    {
        $tape = new ParseTape();
        $ordinal = $tape->allocate(0, ParseTape::NONE, 0, 0);

        $tape->setFlags($ordinal, 0b101);
        self::assertSame(0b101, $tape->flags($ordinal));

        $tape->setFlags($ordinal, 0b010);
        self::assertSame(0b010, $tape->flags($ordinal));
    }

    public function testAddFlagsUnionsBits(): void
    {
        $tape = new ParseTape();
        $ordinal = $tape->allocate(0, ParseTape::NONE, 0, 0);

        $tape->addFlags($ordinal, 0b001);
        $tape->addFlags($ordinal, 0b100);

        self::assertSame(0b101, $tape->flags($ordinal));
    }

    public function testPayloadSetAndGet(): void
    {
        $tape = new ParseTape();
        $ordinal = $tape->allocate(0, ParseTape::NONE, 0, 0);

        self::assertNull($tape->payload($ordinal));

        $tape->setPayload($ordinal, 'php title="x"');

        self::assertSame('php title="x"', $tape->payload($ordinal));
    }

    public function testPayloadPartsAppendWithoutReplacingExistingContent(): void
    {
        $tape = new ParseTape();
        $ordinal = $tape->allocate(0, ParseTape::NONE, 0, 0);

        $tape->appendPayloadPart($ordinal, '1:4', ';');
        $tape->appendPayloadPart($ordinal, '5:8', ';');
        $tape->appendPayloadPart($ordinal, '|php');

        self::assertSame('1:4;5:8|php', $tape->payload($ordinal));
    }

    public function testMutatorsUpdateTheExpectedColumns(): void
    {
        $tape = new ParseTape();
        $root = $tape->allocate(0, ParseTape::NONE, 0, 3);
        $node = $tape->allocate(1, $root, 4, 3);

        $tape->setKindId($node, 7);
        $tape->setParentOrdinal($node, ParseTape::NONE);
        $tape->setEndOffset($node, 12);
        $tape->setFlags($node, 0b001);
        $tape->addFlags($node, 0b100);
        $tape->bumpGeneration($node);
        $tape->setGeneration($node, 9);
        $tape->linkFirstChild($root, $node);
        $tape->linkNextSibling($node, ParseTape::NONE);

        self::assertSame(7, $tape->kindId($node));
        self::assertSame(ParseTape::NONE, $tape->parentOrdinal($node));
        self::assertSame(12, $tape->endOffset($node));
        self::assertSame(0b101, $tape->flags($node));
        self::assertSame(9, $tape->generation($node));
        self::assertSame($node, $tape->firstChildOrdinal($root));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($node));
    }

    public function testColumnSnapshotsStayImmutableAfterTapeMutation(): void
    {
        $tape = new ParseTape();
        $node = $tape->allocateClosed(2, ParseTape::NONE, 3, 8, 1, 4, 'before');
        $snapshot = $tape->columns();

        $tape->setKindId($node, 9);
        $tape->setPayload($node, 'after');

        self::assertSame([2], $snapshot->kind);
        self::assertSame(['before'], $snapshot->payload);
        self::assertSame([9], $tape->columns()->kind);
        self::assertSame(['after'], $tape->columns()->payload);
    }

    public function testExtensionBlockStateDefaultsAndRoundTrips(): void
    {
        $tape = new ParseTape();
        $node = $tape->allocate(0, ParseTape::NONE, 0, 0);
        $state = new BlockState(['label' => 'note', 'open' => true]);

        self::assertInstanceOf(BlockState::class, $tape->extensionBlockState($node));

        $tape->setExtensionBlockState($node, $state);

        self::assertSame($state, $tape->extensionBlockState($node));
    }

    public function testMissingExtensionInlineStateIsExplicit(): void
    {
        $tape = new ParseTape();
        $node = $tape->allocate(0, ParseTape::NONE, 0, 0);

        $this->expectException(SourcePositionException::class);
        $this->expectExceptionMessage(\sprintf('Inline ordinal %d has no extension node.', $node));

        $tape->extensionInlineNode($node);
    }

    public function testCopySubtreePreservesStructureAndOwnedState(): void
    {
        $source = new ParseTape();
        $sourceRoot = $source->allocateClosed(10, ParseTape::NONE, 0, 20, 1, 0b001, 'root');
        $first = $source->appendChild(11, $sourceRoot, ParseTape::NONE, 1, 5, 1, 0b010, 'first');
        $second = $source->appendChild(12, $sourceRoot, $first, 6, 19, 1, 0b100, 'second');
        $grandchild = $source->appendChild(13, $second, ParseTape::NONE, 7, 18, 1);
        $state = new BlockState(['label' => 'custom']);
        $source->setExtensionBlockState($second, $state);
        $inlineNode = new InlineNode('joined');
        $source->setExtensionInlineNode($grandchild, $inlineNode);
        $source->setPayload($grandchild, '^^joined^^');

        $target = new ParseTape();
        $targetRoot = $target->allocate(0, ParseTape::NONE, 0, 0);
        $copy = $target->copySubtreeFrom($source, $sourceRoot, $targetRoot, 30, 50, 7);
        $copiedFirst = $target->firstChildOrdinal($copy);
        $copiedSecond = $target->nextSiblingOrdinal($copiedFirst);
        $copiedGrandchild = $target->firstChildOrdinal($copiedSecond);

        self::assertSame(5, $target->count());
        self::assertSame($targetRoot, $target->parentOrdinal($copy));
        self::assertSame([10, 11, 12, 13], [
            $target->kindId($copy),
            $target->kindId($copiedFirst),
            $target->kindId($copiedSecond),
            $target->kindId($copiedGrandchild),
        ]);
        self::assertSame(['root', 'first', 'second'], [
            $target->payload($copy),
            $target->payload($copiedFirst),
            $target->payload($copiedSecond),
        ]);
        self::assertSame([0b001, 0b010, 0b100], [
            $target->flags($copy),
            $target->flags($copiedFirst),
            $target->flags($copiedSecond),
        ]);
        self::assertSame(30, $target->startOffset($copiedGrandchild));
        self::assertSame(50, $target->endOffset($copiedGrandchild));
        self::assertSame(7, $target->generation($copiedGrandchild));
        self::assertSame($state, $target->extensionBlockState($copiedSecond));
        self::assertSame($inlineNode, $target->extensionInlineNode($copiedGrandchild));
        self::assertSame(
            $inlineNode,
            $target->columns()->extensionInlineNode[$copiedGrandchild],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidOrdinalOperations(): iterable
    {
        foreach ([
            'allocate closed parent',
            'append child parent',
            'append child sibling',
            'link first child parent',
            'link first child target',
            'link next sibling ordinal',
            'link next sibling target',
            'set end',
            'set parent ordinal',
            'set parent target',
            'set flags',
            'bump generation',
            'set generation',
            'set kind',
            'add flags',
            'set payload',
            'set extension state',
            'get extension state',
            'set extension inline node',
            'get extension inline node',
            'append payload',
            'copy source',
            'copy parent',
            'read parent',
            'read first child',
            'read next sibling',
            'read start',
            'read end',
            'read generation',
            'read flags',
            'read payload',
        ] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('invalidOrdinalOperations')]
    public function testEveryCheckedOperationRejectsInvalidOrdinals(string $operation): void
    {
        $tape = new ParseTape();
        $tape->allocate(0, ParseTape::NONE, 0, 0);

        $this->expectException(SourcePositionException::class);

        self::invokeInvalidOrdinalOperation($tape, $operation);
    }

    public function testAllocateRejectsUnknownParent(): void
    {
        $tape = new ParseTape();

        $this->expectException(\OutOfRangeException::class);

        $tape->allocate(0, 5, 0, 0);
    }

    public function testLinkRejectsUnknownSibling(): void
    {
        $tape = new ParseTape();
        $tape->allocate(0, ParseTape::NONE, 0, 0);

        $this->expectException(\OutOfRangeException::class);

        $tape->linkNextSibling(0, 9);
    }

    public function testReadRejectsOutOfRangeOrdinal(): void
    {
        $tape = new ParseTape();
        $tape->allocate(0, ParseTape::NONE, 0, 0);

        $this->expectException(\OutOfRangeException::class);

        $tape->kindId(1);
    }

    public function testReadRejectsNegativeOrdinal(): void
    {
        $this->expectException(\OutOfRangeException::class);

        (new ParseTape())->kindId(-1);
    }

    private static function invokeInvalidOrdinalOperation(ParseTape $tape, string $operation): void
    {
        switch ($operation) {
            case 'allocate closed parent':
                $tape->allocateClosed(0, 9, 0, 1, 0);

                return;
            case 'append child parent':
                $tape->appendChild(0, 9, ParseTape::NONE, 0, 1, 0);

                return;
            case 'append child sibling':
                $tape->appendChild(0, 0, 9, 0, 1, 0);

                return;
            case 'link first child parent':
                $tape->linkFirstChild(9, ParseTape::NONE);

                return;
            case 'link first child target':
                $tape->linkFirstChild(0, 9);

                return;
            case 'link next sibling ordinal':
                $tape->linkNextSibling(9, ParseTape::NONE);

                return;
            case 'link next sibling target':
                $tape->linkNextSibling(0, 9);

                return;
            case 'set end':
                $tape->setEndOffset(9, 1);

                return;
            case 'set parent ordinal':
                $tape->setParentOrdinal(9, ParseTape::NONE);

                return;
            case 'set parent target':
                $tape->setParentOrdinal(0, 9);

                return;
            case 'set flags':
                $tape->setFlags(9, 1);

                return;
            case 'bump generation':
                $tape->bumpGeneration(9);

                return;
            case 'set generation':
                $tape->setGeneration(9, 1);

                return;
            case 'set kind':
                $tape->setKindId(9, 1);

                return;
            case 'add flags':
                $tape->addFlags(9, 1);

                return;
            case 'set payload':
                $tape->setPayload(9, 'value');

                return;
            case 'set extension state':
                $tape->setExtensionBlockState(9, new BlockState());

                return;
            case 'get extension state':
                $tape->extensionBlockState(9);

                return;
            case 'set extension inline node':
                $tape->setExtensionInlineNode(9, new InlineNode('value'));

                return;
            case 'get extension inline node':
                $tape->extensionInlineNode(9);

                return;
            case 'append payload':
                $tape->appendPayloadPart(9, 'value');

                return;
            case 'copy source':
                $tape->copySubtreeFrom(new ParseTape(), 0, ParseTape::NONE, 0, 1, 0);

                return;
            case 'copy parent':
                $source = new ParseTape();
                $source->allocate(0, ParseTape::NONE, 0, 0);
                $tape->copySubtreeFrom($source, 0, 9, 0, 1, 0);

                return;
            case 'read parent':
                $tape->parentOrdinal(9);

                return;
            case 'read first child':
                $tape->firstChildOrdinal(9);

                return;
            case 'read next sibling':
                $tape->nextSiblingOrdinal(9);

                return;
            case 'read start':
                $tape->startOffset(9);

                return;
            case 'read end':
                $tape->endOffset(9);

                return;
            case 'read generation':
                $tape->generation(9);

                return;
            case 'read flags':
                $tape->flags(9);

                return;
            case 'read payload':
                $tape->payload(9);
        }
    }
}
