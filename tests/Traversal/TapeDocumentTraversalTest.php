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

namespace Alto\Markdown\Tests\Traversal;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\UnsupportedDocumentModelException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;
use Alto\Markdown\Traversal\TapeDocumentTraversal;
use Alto\Markdown\Traversal\TraversalOptions;
use Alto\Markdown\Traversal\TraversalVisitor;
use PHPUnit\Framework\TestCase;

final class TapeDocumentTraversalTest extends TestCase
{
    public function testTraversesBlocksInDocumentOrderWithEnterAndLeaveEvents(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            Paragraph.

            - One
            - Two
            MD);
        $visitor = new RecordingTraversalVisitor();

        Instrumentation::reset();
        new TapeDocumentTraversal()->traverse($document->model(), $visitor);

        self::assertSame(1, Instrumentation::$traversals);
        self::assertSame(0, Instrumentation::$inlineParses);
        self::assertSame(0, Instrumentation::$nodeHandles);
        self::assertSame([
            'enter:document:0',
            'enter:atx-heading:1',
            'leave:atx-heading:1',
            'enter:paragraph:1',
            'leave:paragraph:1',
            'enter:list:1',
            'enter:list-item:2',
            'enter:paragraph:3',
            'leave:paragraph:3',
            'leave:list-item:2',
            'enter:list-item:2',
            'enter:paragraph:3',
            'leave:paragraph:3',
            'leave:list-item:2',
            'leave:list:1',
            'leave:document:0',
        ], $visitor->blockLog);
    }

    public function testCanTraverseFromRootNode(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            ## Install

            Body.
            MD);
        $sectionHeading = $document->headings(2)->first();
        self::assertNotNull($sectionHeading);

        $visitor = new RecordingTraversalVisitor();
        new TapeDocumentTraversal()->traverse(
            $document->model(),
            $visitor,
            new TraversalOptions(root: $sectionHeading->id()),
        );

        self::assertSame([
            'enter:atx-heading:0',
            'leave:atx-heading:0',
        ], $visitor->blockLog);
    }

    public function testInlineEventsAreOptInAndLazy(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            Paragraph with `code` and [link](https://example.com).

            ```php
            echo "hello";
            ```
            MD);

        Instrumentation::reset();
        $withoutInlines = new RecordingTraversalVisitor();
        new TapeDocumentTraversal()->traverse($document->model(), $withoutInlines);

        self::assertSame([], $withoutInlines->inlineLog);
        self::assertSame(0, Instrumentation::$inlineParses);

        Instrumentation::reset();
        $withInlines = new RecordingTraversalVisitor();
        new TapeDocumentTraversal()->traverse(
            $document->model(),
            $withInlines,
            new TraversalOptions(includeInlines: true),
        );

        self::assertContains('inline:code-span', $withInlines->inlineLog);
        self::assertContains('inline:link', $withInlines->inlineLog);
        self::assertSame(1, Instrumentation::$inlineParses);
    }

    public function testBlockEventsCarrySourceRanges(): void
    {
        $source = "# Title\n\nParagraph.\n";
        $document = Markdown::github()->fromString($source);
        $visitor = new RecordingTraversalVisitor();

        new TapeDocumentTraversal()->traverse($document->model(), $visitor);

        self::assertSame([0, \strlen($source)], $visitor->ranges['document']);
        self::assertSame([0, \strlen('# Title')], $visitor->ranges['atx-heading']);
        self::assertSame([9, 19], $visitor->ranges['paragraph']);
    }

    public function testTraversalRejectsAnotherDocumentModelImplementation(): void
    {
        $this->expectException(UnsupportedDocumentModelException::class);
        $this->expectExceptionMessage('requires a parsed document model');

        new TapeDocumentTraversal()->traverse(
            self::createStub(DocumentModel::class),
            new RecordingTraversalVisitor(),
        );
    }
}

final class RecordingTraversalVisitor implements TraversalVisitor
{
    /**
     * @var list<string>
     */
    public array $blockLog = [];

    /**
     * @var list<string>
     */
    public array $inlineLog = [];

    /**
     * @var array<string, array{int, int}>
     */
    public array $ranges = [];

    public function enterBlock(BlockEvent $event): void
    {
        $this->blockLog[] = 'enter:' . $event->kind->name . ':' . $event->depth;
        $this->ranges[$event->kind->name] = [$event->range->startOffset, $event->range->endOffset];
    }

    public function leaveBlock(BlockEvent $event): void
    {
        $this->blockLog[] = 'leave:' . $event->kind->name . ':' . $event->depth;
    }

    public function inline(InlineEvent $event): void
    {
        $this->inlineLog[] = 'inline:' . $event->kind;
    }
}
