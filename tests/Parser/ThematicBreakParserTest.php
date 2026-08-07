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

use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\Block\ThematicBreakParser;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThematicBreakParserTest extends TestCase
{
    private static function state(string $markdown): ParserState
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);

        return new ParserState($buffer, $scanner, $map, new ParseTape());
    }

    /**
     * @return array{ParseTape, int}
     */
    private static function parse(string $markdown): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();

        $document = new BlockParser()->parse(new ParserState($buffer, $scanner, $map, $tape));

        return [$tape, $document];
    }

    /**
     * @return list<array{string}>
     */
    public static function markerLines(): array
    {
        return [['***'], ['---'], ['___']];
    }

    #[DataProvider('markerLines')]
    public function testEachMarkerStarts(string $line): void
    {
        $start = new ThematicBreakParser()->tryStart(self::state($line), 0, false);

        self::assertNotNull($start);
        self::assertSame(BlockKind::THEMATIC_BREAK, $start->kind);
    }

    public function testWrongCharacterDoesNotStart(): void
    {
        self::assertNull(new ThematicBreakParser()->tryStart(self::state('+++'), 0, false));
        self::assertNull(new ThematicBreakParser()->tryStart(self::state('==='), 0, false));
    }

    public function testFewerThanThreeMarkersDoesNotStart(): void
    {
        self::assertNull(new ThematicBreakParser()->tryStart(self::state('--'), 0, false));
    }

    public function testMixedMarkersDoNotStart(): void
    {
        self::assertNull(new ThematicBreakParser()->tryStart(self::state('*-*'), 0, false));
    }

    public function testUpToThreeSpacesIndentAllowed(): void
    {
        self::assertNotNull(new ThematicBreakParser()->tryStart(self::state('   ***'), 0, false));
    }

    public function testFourSpacesIndentTooMany(): void
    {
        self::assertNull(new ThematicBreakParser()->tryStart(self::state('    ***'), 0, false));
    }

    public function testSpacesBetweenAndAfterMarkersAllowed(): void
    {
        self::assertNotNull(new ThematicBreakParser()->tryStart(self::state(' - - -'), 0, false));
        self::assertNotNull(new ThematicBreakParser()->tryStart(self::state('- - - -    '), 0, false));
    }

    public function testTrailingNonSpaceCharacterRejects(): void
    {
        self::assertNull(new ThematicBreakParser()->tryStart(self::state('_ _ _ _ a'), 0, false));
        self::assertNull(new ThematicBreakParser()->tryStart(self::state('---a---'), 0, false));
    }

    public function testTryStartDoesNotTouchTape(): void
    {
        $state = self::state('***');
        new ThematicBreakParser()->tryStart($state, 0, false);

        self::assertSame(0, $state->tape()->count());
    }

    public function testParsedThematicBreakIsDocumentChild(): void
    {
        [$tape, $document] = self::parse("***\n");

        $break = $tape->firstChildOrdinal($document);
        self::assertSame(BlockKind::THEMATIC_BREAK, $tape->kindId($break));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($break));
    }

    public function testThematicBreakInterruptsParagraph(): void
    {
        [$tape, $document] = self::parse("Foo\n***\nbar\n");

        $paragraph = $tape->firstChildOrdinal($document);
        $break = $tape->nextSiblingOrdinal($paragraph);
        $tail = $tape->nextSiblingOrdinal($break);

        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame(BlockKind::THEMATIC_BREAK, $tape->kindId($break));
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($tail));
    }
}
