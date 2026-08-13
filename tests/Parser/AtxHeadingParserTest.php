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

use Alto\Markdown\Parser\Block\AtxHeadingParser;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\TestCase;

final class AtxHeadingParserTest extends TestCase
{
    private static function state(string $markdown): ParserState
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);

        return new ParserState($buffer, $scanner, $map, new ParseTape());
    }

    /**
     * @return array{SourceBuffer, ParseTape, int}
     */
    private static function parse(string $markdown): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();

        $document = new BlockParser()->parse(new ParserState($buffer, $scanner, $map, $tape));

        return [$buffer, $tape, $document];
    }

    private static function content(SourceBuffer $buffer, ParseTape $tape, int $ordinal): string
    {
        $payload = $tape->payload($ordinal);
        self::assertNotNull($payload);
        [$start, $end] = array_map('intval', explode(':', $payload));

        return $buffer->substring($start, $end);
    }

    public function testSixLevels(): void
    {
        for ($level = 1; $level <= 6; ++$level) {
            $line = str_repeat('#', $level) . ' foo';
            $start = new AtxHeadingParser()->tryStart(self::state($line), 0, false);

            self::assertNotNull($start, $line);
            self::assertSame(BlockKind::ATX_HEADING, $start->kind);
        }
    }

    public function testSevenHashesIsNotHeading(): void
    {
        self::assertNull(new AtxHeadingParser()->tryStart(self::state('####### foo'), 0, false));
    }

    public function testHashWithoutSpaceIsNotHeading(): void
    {
        self::assertNull(new AtxHeadingParser()->tryStart(self::state('#5 bolt'), 0, false));
        self::assertNull(new AtxHeadingParser()->tryStart(self::state('#hashtag'), 0, false));
    }

    public function testFourSpacesIndentTooMany(): void
    {
        self::assertNull(new AtxHeadingParser()->tryStart(self::state('    # foo'), 0, false));
    }

    public function testLevelAndContentRecorded(): void
    {
        [$buffer, $tape, $document] = self::parse("### foo\n");
        $heading = $tape->firstChildOrdinal($document);

        self::assertSame(BlockKind::ATX_HEADING, $tape->kindId($heading));
        self::assertSame(3, $tape->flags($heading));
        self::assertSame('foo', self::content($buffer, $tape, $heading));
    }

    public function testSurroundingSpacesStrippedFromContent(): void
    {
        [$buffer, $tape, $document] = self::parse("#                  foo                     \n");
        $heading = $tape->firstChildOrdinal($document);

        self::assertSame('foo', self::content($buffer, $tape, $heading));
    }

    public function testClosingSequenceStripped(): void
    {
        [$buffer, $tape, $document] = self::parse("## foo ##\n");
        $heading = $tape->firstChildOrdinal($document);

        self::assertSame(2, $tape->flags($heading));
        self::assertSame('foo', self::content($buffer, $tape, $heading));
    }

    public function testClosingSequenceNeedNotMatchOpeningLength(): void
    {
        [$buffer, $tape, $document] = self::parse("# foo ##################################\n");
        $heading = $tape->firstChildOrdinal($document);

        self::assertSame(1, $tape->flags($heading));
        self::assertSame('foo', self::content($buffer, $tape, $heading));
    }

    public function testHashRunWithFollowingTextIsNotClosing(): void
    {
        [$buffer, $tape, $document] = self::parse("### foo ### b\n");
        $heading = $tape->firstChildOrdinal($document);

        self::assertSame('foo ### b', self::content($buffer, $tape, $heading));
    }

    public function testClosingHashMustBePrecededBySpace(): void
    {
        [$buffer, $tape, $document] = self::parse("# foo#\n");
        $heading = $tape->firstChildOrdinal($document);

        self::assertSame('foo#', self::content($buffer, $tape, $heading));
    }

    public function testEmptyHeadings(): void
    {
        [$buffer, $tape, $document] = self::parse("## \n#\n### ###\n");

        $first = $tape->firstChildOrdinal($document);
        $second = $tape->nextSiblingOrdinal($first);
        $third = $tape->nextSiblingOrdinal($second);

        self::assertSame(2, $tape->flags($first));
        self::assertSame('', self::content($buffer, $tape, $first));
        self::assertSame(1, $tape->flags($second));
        self::assertSame('', self::content($buffer, $tape, $second));
        self::assertSame(3, $tape->flags($third));
        self::assertSame('', self::content($buffer, $tape, $third));
    }

    public function testHeadingInterruptsParagraph(): void
    {
        [, $tape, $document] = self::parse("Foo bar\n# baz\nBar foo\n");

        $paragraph = $tape->firstChildOrdinal($document);
        $heading = $tape->nextSiblingOrdinal($paragraph);
        $tail = $tape->nextSiblingOrdinal($heading);

        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame(BlockKind::ATX_HEADING, $tape->kindId($heading));
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($tail));
    }
}
