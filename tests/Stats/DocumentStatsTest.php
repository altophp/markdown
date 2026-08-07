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

namespace Alto\Markdown\Tests\Stats;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\Id\InlineNodeId;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Stats\DocumentStatsCollector;
use Alto\Markdown\Stats\SectionWordCount;
use Alto\Markdown\Stats\Traversal\StatsVisitor;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\DocumentTraversal;
use Alto\Markdown\Traversal\InlineEvent;
use PHPUnit\Framework\TestCase;

final class DocumentStatsTest extends TestCase
{
    public function testStatsCollectDocumentSnapshot(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            Intro with [external](https://example.com/path) and [internal](#details).

            ## Details

            More text with `code span` and ![logo](/logo.png).

            ```php
            echo "hello";
            ```

            | A | B |
            | - | - |
            | 1 | 2 |
            MD);

        $stats = $document->stats();

        self::assertSame(2, $stats->headingCount);
        self::assertSame([1 => 1, 2 => 1], $stats->headingsByLevel);
        self::assertSame(2, $stats->maxHeadingDepth);
        self::assertSame(['# Title', '## Details'], $stats->outline);
        self::assertSame(2, $stats->linkCount);
        self::assertSame(['internal' => 1, 'external' => 1], $stats->linksByType);
        self::assertSame(['example.com' => 1], $stats->linksByHost);
        self::assertSame(1, $stats->imageCount);
        self::assertSame(1, $stats->codeBlockCount);
        self::assertSame(['php' => 1], $stats->codeBlocksByLanguage);
        self::assertSame(1, $stats->tableCount);
        self::assertSame(14, $stats->wordCount);
        self::assertSame(1, $stats->readingTimeMinutes);
        self::assertEquals(
            [
                new SectionWordCount('Title', 1, 6),
                new SectionWordCount('Details', 2, 8),
            ],
            $stats->sectionWordCounts,
        );
    }

    public function testStatsUseTraversalWithoutMaterializingHandles(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            Paragraph with [link](https://example.com).
            MD);

        Instrumentation::reset();
        $stats = $document->stats();

        self::assertSame(1, $stats->headingCount);
        self::assertSame(1, $stats->linkCount);
        self::assertSame(1, Instrumentation::$traversals);
        self::assertSame(0, Instrumentation::$nodeHandles);
        self::assertSame(0, Instrumentation::$headingHandles);
        self::assertSame(0, Instrumentation::$linkHandles);
        self::assertSame(2, Instrumentation::$inlineParses);
    }

    public function testDuplicateSectionTitlesRemainDistinctAndOrdered(): void
    {
        $stats = Markdown::github()->fromString(<<<'MD'
            # Repeat

            One two.

            ## Repeat

            Three.
            MD)->stats();

        self::assertEquals(
            [
                new SectionWordCount('Repeat', 1, 3),
                new SectionWordCount('Repeat', 2, 2),
            ],
            $stats->sectionWordCounts,
        );
    }

    public function testEmptyDocumentHasZeroReadingTime(): void
    {
        $stats = Markdown::github()->fromString('')->stats();

        self::assertSame(0, $stats->wordCount);
        self::assertSame(0, $stats->characterCount);
        self::assertSame(0, $stats->readingTimeMinutes);
        self::assertSame([], $stats->sectionWordCounts);
    }

    public function testWordsAndCharactersUseDecodedVisibleInlineText(): void
    {
        $stats = Markdown::github()->fromString("A &amp; B\n")->stats();

        self::assertSame(2, $stats->wordCount);
        self::assertSame(5, $stats->characterCount);
        self::assertSame(1, $stats->readingTimeMinutes);
    }

    public function testMultiByteCharactersAreCountedAsCharactersNotBytes(): void
    {
        $stats = Markdown::github()->fromString("café 日本語\n")->stats();

        self::assertSame(8, $stats->characterCount);
    }

    public function testExtendedAutolinksContributeTheirVisibleText(): void
    {
        $stats = Markdown::github()->fromString("https://example.com/docs\n")->stats();

        self::assertSame(4, $stats->wordCount);
        self::assertSame(24, $stats->characterCount);
    }

    public function testInvalidUtf8HasNoWordsAndFallsBackToByteCharacters(): void
    {
        $stats = Markdown::github()->fromString("\xFF\n")->stats();

        self::assertSame(0, $stats->wordCount);
        self::assertSame(1, $stats->characterCount);
        self::assertSame(0, $stats->readingTimeMinutes);
    }

    public function testReadingTimeRoundsUpAtTwoHundredWords(): void
    {
        $stats = Markdown::github()->fromString(str_repeat('word ', 201))->stats();

        self::assertSame(201, $stats->wordCount);
        self::assertSame(2, $stats->readingTimeMinutes);
    }

    public function testCollectorSupportsAnotherModelThroughItsTraversalContract(): void
    {
        $model = self::createStub(DocumentModel::class);
        $traversal = $this->createMock(DocumentTraversal::class);
        $traversal->expects(self::once())->method('traverse');

        $stats = new DocumentStatsCollector($traversal)->collect($model);

        self::assertSame(0, $stats->wordCount);
    }

    public function testCoreVisitorIgnoresForeignModelEvents(): void
    {
        $model = self::createStub(DocumentModel::class);
        $range = new SourceRange(0, 0);
        $visitor = new StatsVisitor();

        $visitor->enterBlock(new BlockEvent(
            $model,
            new NodeId(0, 0),
            new NodeKind(1, 'atx-heading'),
            $range,
            0,
        ));
        $visitor->inline(new InlineEvent(
            $model,
            new NodeId(0, 0),
            new InlineNodeId(0, 0),
            'link',
            $range,
        ));

        self::assertSame(0, $visitor->stats()->headingCount);
        self::assertSame(0, $visitor->stats()->linkCount);
    }
}
