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
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Tests\Conformance\TestHtmlRenderer;
use PHPUnit\Framework\TestCase;

final class IndentedCodeParserTest extends TestCase
{
    /**
     * @return array{ParseTape, int}
     */
    private static function parse(string $markdown): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $map, $tape);

        $document = new BlockParser()->parse($state);

        return [$tape, $document];
    }

    private static function render(string $markdown): string
    {
        return new TestHtmlRenderer()->render($markdown);
    }

    public function testSimpleBlockKeepsInteriorIndentation(): void
    {
        self::assertSame(
            "<pre><code>a simple\n  indented code block\n</code></pre>\n",
            self::render("    a simple\n      indented code block\n"),
        );
    }

    public function testFirstChildIsIndentedCodeWithPayload(): void
    {
        [$tape, $document] = self::parse("    foo\n");

        $code = $tape->firstChildOrdinal($document);

        self::assertSame(BlockKind::INDENTED_CODE, $tape->kindId($code));
        self::assertSame('0:7', $tape->payload($code));
    }

    public function testCannotInterruptParagraph(): void
    {
        [$tape, $document] = self::parse("Foo\n    bar\n");

        $paragraph = $tape->firstChildOrdinal($document);

        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($paragraph));
        self::assertSame("<p>Foo\nbar</p>\n", self::render("Foo\n    bar\n"));
    }

    public function testFewerThanFourSpacesEndsBlock(): void
    {
        self::assertSame(
            "<pre><code>foo\n</code></pre>\n<p>bar</p>\n",
            self::render("    foo\nbar\n"),
        );
    }

    public function testInteriorBlankLinesStayTrailingBlanksDrop(): void
    {
        self::assertSame(
            "<pre><code>chunk1\n\nchunk2\n\n\n\nchunk3\n</code></pre>\n",
            self::render("    chunk1\n\n    chunk2\n  \n \n \n    chunk3\n"),
        );
    }

    public function testTabCountsAsFourColumnsOfIndent(): void
    {
        self::assertSame(
            "<pre><code>foo\tbaz\n</code></pre>\n",
            self::render("\tfoo\tbaz\n"),
        );
    }

    public function testMoreThanFourLeadingSpacesArePreserved(): void
    {
        self::assertSame(
            "<pre><code>    foo\nbar\n</code></pre>\n",
            self::render("        foo\n    bar\n"),
        );
    }

    public function testTrailingWhitespaceIsKept(): void
    {
        self::assertSame(
            "<pre><code>foo  \n</code></pre>\n",
            self::render("    foo  \n"),
        );
    }
}
