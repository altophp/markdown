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
use Alto\Markdown\Parser\Block\HtmlBlockParser;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\TestCase;

final class HtmlBlockParserTest extends TestCase
{
    /**
     * @return array{ParseTape, SourceBuffer}
     */
    private static function parse(string $markdown): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $map, $tape);

        new BlockParser()->parse($state);

        return [$tape, $buffer];
    }

    /**
     * Ordinal of the first HTML block on the tape, or NONE. Slots are
     * append-only, so a linear scan is stable and ignores the throwaway child
     * blocks the core loop allocates inside a raw HTML range.
     */
    private static function htmlBlock(ParseTape $tape): int
    {
        for ($ordinal = 0; $ordinal < $tape->count(); ++$ordinal) {
            if (BlockKind::HTML_BLOCK === $tape->kindId($ordinal)) {
                return $ordinal;
            }
        }

        return ParseTape::NONE;
    }

    private static function raw(ParseTape $tape, SourceBuffer $buffer, int $ordinal): string
    {
        return $buffer->substring($tape->startOffset($ordinal), $tape->endOffset($ordinal));
    }

    public function testType1PreEndsOnClosingTagLine(): void
    {
        [$tape, $buffer] = self::parse("<pre>\nfoo\n</pre>\nbar\n");

        $html = self::htmlBlock($tape);
        self::assertNotSame(ParseTape::NONE, $html);
        self::assertSame(1, $tape->flags($html));
        self::assertSame('0:16', $tape->payload($html));
        self::assertSame("<pre>\nfoo\n</pre>\n", self::raw($tape, $buffer, $html));
    }

    public function testType1ClosesOnStartLineWithEndTag(): void
    {
        [$tape, $buffer] = self::parse("<style>x</style>\nfoo\n");

        $html = self::htmlBlock($tape);
        self::assertSame(1, $tape->flags($html));
        self::assertSame("<style>x</style>\n", self::raw($tape, $buffer, $html));
    }

    public function testType1CaseInsensitiveAndBlankLinesInside(): void
    {
        [$tape, $buffer] = self::parse("<SCRIPT>\n\nvar x;\n</SCRIPT>\nokay\n");

        $html = self::htmlBlock($tape);
        self::assertSame(1, $tape->flags($html));
        self::assertSame("<SCRIPT>\n\nvar x;\n</SCRIPT>\n", self::raw($tape, $buffer, $html));
    }

    public function testType2CommentSpansUntilEndMarker(): void
    {
        [$tape, $buffer] = self::parse("<!-- a\nb -->\nfoo\n");

        $html = self::htmlBlock($tape);
        self::assertSame(2, $tape->flags($html));
        self::assertSame("<!-- a\nb -->\n", self::raw($tape, $buffer, $html));
    }

    public function testType3ProcessingInstruction(): void
    {
        [$tape, $buffer] = self::parse("<?php\n echo 1;\n?>\nfoo\n");

        $html = self::htmlBlock($tape);
        self::assertSame(3, $tape->flags($html));
        self::assertSame("<?php\n echo 1;\n?>\n", self::raw($tape, $buffer, $html));
    }

    public function testType4Declaration(): void
    {
        [$tape, $buffer] = self::parse("<!DOCTYPE html>\nfoo\n");

        $html = self::htmlBlock($tape);
        self::assertSame(4, $tape->flags($html));
        self::assertSame("<!DOCTYPE html>\n", self::raw($tape, $buffer, $html));
    }

    public function testType5Cdata(): void
    {
        [$tape, $buffer] = self::parse("<![CDATA[\nx < y\n]]>\nfoo\n");

        $html = self::htmlBlock($tape);
        self::assertSame(5, $tape->flags($html));
        self::assertSame("<![CDATA[\nx < y\n]]>\n", self::raw($tape, $buffer, $html));
    }

    public function testType5CdataIsCaseSensitive(): void
    {
        // Lowercase cdata is not a type-5 start; it is not a known type-6 tag
        // and not a complete tag, so no HTML block opens.
        [$tape] = self::parse("<![cdata[\nx\n]]>\n");

        self::assertSame(ParseTape::NONE, self::htmlBlock($tape));
    }

    public function testType6KnownTagEndsAtBlankLine(): void
    {
        [$tape, $buffer] = self::parse("<div>\nfoo\n\nbar\n");

        $html = self::htmlBlock($tape);
        self::assertSame(6, $tape->flags($html));
        self::assertSame("<div>\nfoo\n", self::raw($tape, $buffer, $html));
    }

    public function testType6ClosingTag(): void
    {
        [$tape, $buffer] = self::parse("</div>\n*foo*\n");

        $html = self::htmlBlock($tape);
        self::assertSame(6, $tape->flags($html));
        self::assertSame("</div>\n*foo*\n", self::raw($tape, $buffer, $html));
    }

    public function testType7CompleteTagOnItsOwnLine(): void
    {
        // "warning" is not a type-6 tag, so the complete open tag makes a type-7
        // block that ends at the blank line.
        [$tape, $buffer] = self::parse("<warning>\n*bar*\n\nbaz\n");

        $html = self::htmlBlock($tape);
        self::assertSame(7, $tape->flags($html));
        self::assertSame("<warning>\n*bar*\n", self::raw($tape, $buffer, $html));
    }

    public function testType7RejectsTagFollowedByOtherText(): void
    {
        // A tag not alone on its line is inline HTML, not a block.
        [$tape] = self::parse("<a href=\"x\">y</a>\n");

        self::assertSame(ParseTape::NONE, self::htmlBlock($tape));
    }

    public function testTypesOneToSixInterruptParagraph(): void
    {
        [$tape, $buffer] = self::parse("Foo\n<div>\nbar\n");

        $paragraph = $tape->firstChildOrdinal(0);
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame(0, $tape->startOffset($paragraph));
        self::assertSame(3, $tape->endOffset($paragraph));

        $html = self::htmlBlock($tape);
        self::assertSame(6, $tape->flags($html));
        self::assertSame("<div>\nbar\n", self::raw($tape, $buffer, $html));
    }

    public function testType7CannotInterruptParagraph(): void
    {
        [$tape] = self::parse("Foo\n<a href=\"bar\">\nbaz\n");

        self::assertSame(ParseTape::NONE, self::htmlBlock($tape));

        $paragraph = $tape->firstChildOrdinal(0);
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($paragraph));
    }

    public function testBlankLineTerminatesBlockAndParagraphFollows(): void
    {
        [$tape, $buffer] = self::parse("<div>\nline\n\nafter\n");

        $html = self::htmlBlock($tape);
        self::assertSame(6, $tape->flags($html));
        // Block ends before the blank line; "after" is a separate paragraph.
        self::assertSame("<div>\nline\n", self::raw($tape, $buffer, $html));

        $trailing = $tape->nextSiblingOrdinal($html);
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($trailing));
        self::assertSame('after', self::raw($tape, $buffer, $trailing));
    }

    public function testUpToThreeSpacesOfIndentAllowedAndPreserved(): void
    {
        [$tape, $buffer] = self::parse("   <div>\nfoo\n");

        $html = self::htmlBlock($tape);
        self::assertSame(6, $tape->flags($html));
        self::assertSame(0, $tape->startOffset($html));
        self::assertSame("   <div>\nfoo\n", self::raw($tape, $buffer, $html));
    }

    public function testFourSpacesOfIndentIsNotHtmlBlock(): void
    {
        [$tape] = self::parse("    <div>\n");

        self::assertSame(ParseTape::NONE, self::htmlBlock($tape));
    }

    public function testUnknownBareTagDoesNotStartBlock(): void
    {
        // "<div" without a delimiter after the name is not a type-6 start, and
        // an incomplete tag is not type 7; it stays a paragraph.
        [$tape] = self::parse("<divxyz\n");

        self::assertSame(ParseTape::NONE, self::htmlBlock($tape));
    }

    public function testHtmlConstructRejectsBlankAndNonTagLinesDirectly(): void
    {
        $parser = new HtmlBlockParser();

        self::assertNull($parser->tryStart(self::state("   \n"), 0, false));
        self::assertNull($parser->tryStart(self::state("plain text\n"), 0, false));
    }

    public function testType6AcceptsOpenEndedAndSelfClosingKnownTags(): void
    {
        [$openEnded] = self::parse('<div');
        [$selfClosing] = self::parse("<div/>\n");

        self::assertSame(6, $openEnded->flags(self::htmlBlock($openEnded)));
        self::assertSame(6, $selfClosing->flags(self::htmlBlock($selfClosing)));
    }

    public function testMalformedType7AttributesDoNotOpenHtmlBlocks(): void
    {
        [$trailingSpace] = self::parse('<widget ');
        [$missingValue] = self::parse('<widget attr=');

        self::assertSame(ParseTape::NONE, self::htmlBlock($trailingSpace));
        self::assertSame(ParseTape::NONE, self::htmlBlock($missingValue));
    }

    private static function state(string $markdown): ParserState
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);

        return new ParserState($buffer, $scanner, $map, new ParseTape());
    }
}
