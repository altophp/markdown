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
use Alto\Markdown\Parser\Block\SetextHeadingParser;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\TestCase;

final class SetextHeadingParserTest extends TestCase
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
        $lines = [];

        foreach (explode(';', $payload) as $pair) {
            [$start, $end] = array_map('intval', explode(':', $pair));
            $lines[] = $buffer->substring($start, $end);
        }

        return implode("\n", $lines);
    }

    public function testUnderlineNeedsOpenParagraph(): void
    {
        self::assertNull(new SetextHeadingParser()->tryStart(self::state('==='), 0, false));
        self::assertNotNull(new SetextHeadingParser()->tryStart(self::state('==='), 0, true));
    }

    public function testReplacesParagraphFlagSet(): void
    {
        $start = new SetextHeadingParser()->tryStart(self::state('---'), 0, true);

        self::assertNotNull($start);
        self::assertTrue($start->replacesParagraph);
    }

    public function testInternalSpaceRejectsUnderline(): void
    {
        self::assertNull(new SetextHeadingParser()->tryStart(self::state('= ='), 0, true));
        self::assertNull(new SetextHeadingParser()->tryStart(self::state('--- -'), 0, true));
    }

    public function testFourSpacesIndentTooMany(): void
    {
        self::assertNull(new SetextHeadingParser()->tryStart(self::state('    ---'), 0, true));
    }

    public function testTrailingSpacesAllowed(): void
    {
        self::assertNotNull(new SetextHeadingParser()->tryStart(self::state('   ----      '), 0, true));
    }

    public function testEqualsProducesLevelOne(): void
    {
        [$buffer, $tape, $document] = self::parse("Foo\n===\n");
        $heading = $tape->firstChildOrdinal($document);

        self::assertSame(BlockKind::SETEXT_HEADING, $tape->kindId($heading));
        self::assertSame(1, $tape->flags($heading));
        self::assertSame('Foo', self::content($buffer, $tape, $heading));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($heading));
    }

    public function testDashProducesLevelTwo(): void
    {
        [$buffer, $tape, $document] = self::parse("Foo\n---\n");
        $heading = $tape->firstChildOrdinal($document);

        self::assertSame(2, $tape->flags($heading));
        self::assertSame('Foo', self::content($buffer, $tape, $heading));
    }

    public function testMultiLineContentIsConcatenated(): void
    {
        [$buffer, $tape, $document] = self::parse("Foo\nBar\n---\n");
        $heading = $tape->firstChildOrdinal($document);

        self::assertSame(2, $tape->flags($heading));
        self::assertSame("Foo\nBar", self::content($buffer, $tape, $heading));
    }

    public function testDashUnderlineWinsOverThematicBreak(): void
    {
        [, $tape, $document] = self::parse("Foo\n---\nbar\n");

        $heading = $tape->firstChildOrdinal($document);
        $tail = $tape->nextSiblingOrdinal($heading);

        self::assertSame(BlockKind::SETEXT_HEADING, $tape->kindId($heading));
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($tail));
    }

    public function testTryStartDoesNotTouchTape(): void
    {
        $state = self::state('===');
        new SetextHeadingParser()->tryStart($state, 0, true);

        self::assertSame(0, $state->tape()->count());
    }
}
