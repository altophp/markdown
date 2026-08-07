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

use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\Block\IndentedCodeParser;
use Alto\Markdown\Parser\Block\ListItemParser;
use Alto\Markdown\Parser\Block\SetextHeadingParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\TestCase;

final class BlockConstructEdgeTest extends TestCase
{
    public function testMultilineParagraphCannotBecomeAGfmTableHeader(): void
    {
        self::assertSame(
            "<p>first line\nsecond line\n| --- |</p>\n",
            Markdown::gfm()->toHtml("first line\nsecond line\n| --- |\n"),
        );
    }

    public function testListItemStartRequiresAMarkerCompatibleWithItsList(): void
    {
        $parser = new ListItemParser();
        [$plain, $plainList] = self::listState("plain\n", \ord('-'));
        [$bullet, $plusList] = self::listState("- item\n", \ord('+'));

        self::assertNull($parser->tryStart($plain, $plainList, false));
        self::assertNull($parser->tryStart($bullet, $plusList, false));
    }

    public function testLeafConstructsHandleDirectLifecycleBoundaries(): void
    {
        $blank = self::state("   \n");
        $plain = self::state("plain\n");
        $indented = new IndentedCodeParser();
        $ordinal = $blank->tape->allocate(BlockKind::INDENTED_CODE, ParseTape::NONE, 0, 0);

        self::assertNull($indented->tryStart($blank, 0, false));
        $indented->close($blank, $ordinal);
        self::assertNull($blank->tape->payload($ordinal));
        self::assertNull(new SetextHeadingParser()->tryStart($plain, 0, true));
    }

    /**
     * @return array{ParserState, int}
     */
    private static function listState(string $source, int $signature): array
    {
        $buffer = new SourceBuffer($source);
        $scanner = new LineScanner($buffer);
        $tape = new ParseTape();
        $document = $tape->allocateClosed(
            BlockKind::DOCUMENT,
            ParseTape::NONE,
            0,
            \strlen($source),
            0,
        );
        $list = $tape->appendChild(
            BlockKind::LIST,
            $document,
            ParseTape::NONE,
            0,
            \strlen($source),
            0,
            $signature << 8,
        );

        return [
            new ParserState($buffer, $scanner, new LineMap($buffer, $scanner), $tape),
            $list,
        ];
    }

    private static function state(string $source): ParserState
    {
        $buffer = new SourceBuffer($source);
        $scanner = new LineScanner($buffer);

        return new ParserState($buffer, $scanner, new LineMap($buffer, $scanner), new ParseTape());
    }
}
