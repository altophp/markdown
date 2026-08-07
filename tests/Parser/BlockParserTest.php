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
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Profile\GitHubProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use PHPUnit\Framework\TestCase;

final class BlockParserTest extends TestCase
{
    /**
     * @return array{ParseTape, int}
     */
    private static function parse(string $markdown, ?BlockParser $parser = null): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $map, $tape);

        $document = ($parser ?? new BlockParser())->parse($state);

        return [$tape, $document];
    }

    public function testEmptyInputYieldsDocumentOnly(): void
    {
        [$tape, $document] = self::parse('');

        self::assertSame(1, $tape->count());
        self::assertSame(BlockKind::DOCUMENT, $tape->kindId($document));
        self::assertSame(ParseTape::NONE, $tape->firstChildOrdinal($document));
    }

    public function testBlankOnlyInputYieldsDocumentOnly(): void
    {
        [$tape, $document] = self::parse("   \n\t\n\n");

        self::assertSame(1, $tape->count());
        self::assertSame(ParseTape::NONE, $tape->firstChildOrdinal($document));
    }

    public function testMultiLineParagraphMergesIntoOneBlock(): void
    {
        [$tape, $document] = self::parse("aaa\nbbb\nccc\n");

        $paragraph = $tape->firstChildOrdinal($document);
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($paragraph));
        self::assertSame(0, $tape->startOffset($paragraph));
        self::assertSame(11, $tape->endOffset($paragraph));
    }

    public function testBlankLineSeparatesParagraphs(): void
    {
        [$tape, $document] = self::parse("aaa\n\nbbb\n");

        $first = $tape->firstChildOrdinal($document);
        $second = $tape->nextSiblingOrdinal($first);

        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($first));
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($second));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($second));
        self::assertSame(0, $tape->startOffset($first));
        self::assertSame(3, $tape->endOffset($first));
        self::assertSame(5, $tape->startOffset($second));
        self::assertSame(8, $tape->endOffset($second));
    }

    public function testLeadingAndTrailingBlankLinesAreDropped(): void
    {
        [$tape, $document] = self::parse("\n\naaa\n\n\n");

        $paragraph = $tape->firstChildOrdinal($document);
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame(2, $tape->startOffset($paragraph));
        self::assertSame(5, $tape->endOffset($paragraph));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($paragraph));
    }

    public function testCrlfOffsetsStayByteExact(): void
    {
        [$tape, $document] = self::parse("aaa\r\nbbb\r\n\r\nccc\r\n");

        $first = $tape->firstChildOrdinal($document);
        $second = $tape->nextSiblingOrdinal($first);

        self::assertSame(0, $tape->startOffset($first));
        self::assertSame(8, $tape->endOffset($first));
        self::assertSame(12, $tape->startOffset($second));
        self::assertSame(15, $tape->endOffset($second));
    }

    public function testBomKeepsOriginalByteOffsets(): void
    {
        [$tape, $document] = self::parse("\xEF\xBB\xBFaaa\n");

        $paragraph = $tape->firstChildOrdinal($document);
        self::assertSame(3, $tape->startOffset($paragraph));
        self::assertSame(6, $tape->endOffset($paragraph));
        self::assertSame(0, $tape->startOffset($document));
    }

    public function testIndentedContinuationStaysInParagraph(): void
    {
        [$tape, $document] = self::parse("aaa\n   bbb\n");

        $paragraph = $tape->firstChildOrdinal($document);
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($paragraph));
        self::assertSame(0, $tape->startOffset($paragraph));
        self::assertSame(10, $tape->endOffset($paragraph));
    }

    public function testDocumentEndCoversWholeInput(): void
    {
        [$tape, $document] = self::parse("aaa\n\nbbb");

        self::assertSame(8, $tape->endOffset($document));
    }

    public function testTapeDumpIsIdenticalAcrossParses(): void
    {
        [$tapeA] = self::parse("aaa\n\nbbb\nccc\n");
        [$tapeB] = self::parse("aaa\n\nbbb\nccc\n");

        $dumper = new TapeDumper();

        self::assertNotSame('', $dumper->dump($tapeA));
        self::assertSame($dumper->dump($tapeA), $dumper->dump($tapeB));
    }

    public function testParserStateAccessorsDescribeAnEmptySource(): void
    {
        $state = self::state('');

        self::assertSame(0, $state->offset());
        self::assertTrue($state->atLineEnd());
        self::assertSame('', $state->remainingOnLine());
        self::assertInstanceOf(LineMap::class, $state->map());
    }

    public function testParserStateRejectsBackwardAdvance(): void
    {
        $state = self::state('abc');
        $state->advanceTo(2);

        $this->expectException(SourcePositionException::class);
        $this->expectExceptionMessage('Cannot advance from 2 to 1');

        $state->advanceTo(1);
    }

    public function testParserStateRejectsAColumnOutsideTheLine(): void
    {
        $state = self::state('abc');

        $this->expectException(SourcePositionException::class);
        $this->expectExceptionMessage('Offset 4 is outside line 0');

        $state->columnAt(4);
    }

    public function testAdvanceToColumnStopsAtContent(): void
    {
        $state = self::state('abc');

        $state->advanceToColumn(1);

        self::assertSame(0, $state->offset());
    }

    public function testTimedComplexContainersBalanceEveryParserStage(): void
    {
        Instrumentation::measure();

        try {
            self::parse("> quote\n    lazy continuation\n> ```\n> code\n> ```\n\n- item\n");

            self::assertGreaterThan(0, Instrumentation::$blockTryContinueCalls);
            self::assertGreaterThan(0, Instrumentation::$blockTryStartCalls);
            self::assertGreaterThan(0, Instrumentation::$blockParagraphAppends);
            self::assertArrayHasKey('block-p1', Instrumentation::$stageEnters);
            self::assertArrayHasKey('block-p2', Instrumentation::$stageEnters);
            self::assertArrayHasKey('block-close', Instrumentation::$stageEnters);
            self::assertSame(0, Instrumentation::regionDepth());
        } finally {
            Instrumentation::disable();
        }
    }

    public function testReferenceDefinitionPrefixBeforeAListKeepsParagraphRemainder(): void
    {
        $parser = new BlockParser();
        [$tape, $document] = self::parse("[guide]: /docs\nremaining text\n- item\n", $parser);
        $definition = $tape->firstChildOrdinal($document);
        $paragraph = $tape->nextSiblingOrdinal($definition);
        $list = $tape->nextSiblingOrdinal($paragraph);

        self::assertSame(BlockKind::LINK_REFERENCE_DEFINITION, $tape->kindId($definition));
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame(BlockKind::LIST, $tape->kindId($list));
        self::assertSame(1, $parser->referenceMap()->count());
    }

    public function testInvalidTaskMarkerRemainsOrdinaryListText(): void
    {
        $profile = new ProfileCompiler()->compile(new GitHubProfile());
        [$tape, $document] = self::parse("- [q] item\n", new BlockParser($profile));
        $list = $tape->firstChildOrdinal($document);
        $item = $tape->firstChildOrdinal($list);

        self::assertSame(BlockKind::LIST_ITEM, $tape->kindId($item));
        self::assertNull($tape->payload($item));
    }

    private static function state(string $source): ParserState
    {
        $buffer = new SourceBuffer($source);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);

        return new ParserState($buffer, $scanner, $map, new ParseTape());
    }
}
