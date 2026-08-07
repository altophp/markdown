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

use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\TestCase;

final class TapeDumperTest extends TestCase
{
    public function testEmptyTapeDumpsToEmptyString(): void
    {
        self::assertSame('', (new TapeDumper())->dump(new ParseTape()));
    }

    public function testSingleNodeDump(): void
    {
        $tape = new ParseTape();
        $ordinal = $tape->allocate(3, ParseTape::NONE, 0, 0);
        $tape->setEndOffset($ordinal, 12);

        self::assertSame(
            "#0 kind=3 [0..12] flags=0\n",
            (new TapeDumper())->dump($tape),
        );
    }

    public function testUnknownEndOffsetRendersAsSentinel(): void
    {
        $tape = new ParseTape();
        $tape->allocate(1, ParseTape::NONE, 5, 0);

        self::assertSame(
            "#0 kind=1 [5..-1] flags=0\n",
            (new TapeDumper())->dump($tape),
        );
    }

    public function testNestedTreeIsIndentedInDocumentOrder(): void
    {
        $tape = new ParseTape();
        $doc = $tape->allocate(0, ParseTape::NONE, 0, 0);
        $heading = $tape->allocate(1, $doc, 0, 0);
        $paragraph = $tape->allocate(2, $doc, 10, 0);

        $tape->linkFirstChild($doc, $heading);
        $tape->linkNextSibling($heading, $paragraph);
        $tape->setEndOffset($doc, 20);
        $tape->setEndOffset($heading, 9);
        $tape->setEndOffset($paragraph, 20);

        $expected = "#0 kind=0 [0..20] flags=0\n"
            ."  #1 kind=1 [0..9] flags=0\n"
            ."  #2 kind=2 [10..20] flags=0\n";

        self::assertSame($expected, (new TapeDumper())->dump($tape));
    }

    public function testTraversalFollowsSiblingLinksNotOrdinalOrder(): void
    {
        $tape = new ParseTape();
        $root = $tape->allocate(0, ParseTape::NONE, 0, 0);
        $first = $tape->allocate(1, $root, 0, 0);
        $second = $tape->allocate(2, $root, 0, 0);

        // Wire the later-allocated ordinal (2) ahead of ordinal 1. Ordinal
        // order would print #1 before #2; link order prints #2 first.
        $tape->linkFirstChild($root, $second);
        $tape->linkNextSibling($second, $first);

        $expected = "#0 kind=0 [0..-1] flags=0\n"
            ."  #2 kind=2 [0..-1] flags=0\n"
            ."  #1 kind=1 [0..-1] flags=0\n";

        self::assertSame($expected, (new TapeDumper())->dump($tape));
    }

    public function testTopLevelSiblingLinksOrderRoots(): void
    {
        $tape = new ParseTape();
        $first = $tape->allocate(1, ParseTape::NONE, 0, 0);
        $second = $tape->allocate(2, ParseTape::NONE, 5, 0);

        // Chain the two top-level roots so the head (ordinal 1) leads.
        $tape->linkNextSibling($second, $first);

        $expected = "#1 kind=2 [5..-1] flags=0\n"
            ."#0 kind=1 [0..-1] flags=0\n";

        self::assertSame($expected, (new TapeDumper())->dump($tape));
    }

    public function testDisconnectedRootsDumpInAllocationOrder(): void
    {
        $tape = new ParseTape();
        $tape->allocate(1, ParseTape::NONE, 0, 0);
        $tape->allocate(2, ParseTape::NONE, 5, 0);

        $expected = "#0 kind=1 [0..-1] flags=0\n"
            ."#1 kind=2 [5..-1] flags=0\n";

        self::assertSame($expected, (new TapeDumper())->dump($tape));
    }

    public function testFlagsAppearInDump(): void
    {
        $tape = new ParseTape();
        $ordinal = $tape->allocate(1, ParseTape::NONE, 0, 0);
        $tape->setFlags($ordinal, 6);

        self::assertSame(
            "#0 kind=1 [0..-1] flags=6\n",
            (new TapeDumper())->dump($tape),
        );
    }

    public function testPayloadIsQuotedAndEscaped(): void
    {
        $tape = new ParseTape();
        $ordinal = $tape->allocate(5, ParseTape::NONE, 0, 0);
        $tape->setEndOffset($ordinal, 30);
        $tape->setPayload($ordinal, "php\ttitle");

        self::assertSame(
            "#0 kind=5 [0..30] flags=0 payload=\"php\\ttitle\"\n",
            (new TapeDumper())->dump($tape),
        );
    }

    public function testSameCallSequenceProducesByteIdenticalDump(): void
    {
        self::assertSame(
            (new TapeDumper())->dump($this->sampleTree()),
            (new TapeDumper())->dump($this->sampleTree()),
        );
    }

    public function testDumpingOneTapeTwiceIsByteIdentical(): void
    {
        $tape = $this->sampleTree();
        $dumper = new TapeDumper();

        self::assertSame($dumper->dump($tape), $dumper->dump($tape));
    }

    private function sampleTree(): ParseTape
    {
        $tape = new ParseTape();
        $doc = $tape->allocate(0, ParseTape::NONE, 0, 0);
        $heading = $tape->allocate(1, $doc, 0, 0);
        $code = $tape->allocate(2, $doc, 8, 0);
        $para = $tape->allocate(3, $doc, 40, 0);

        $tape->linkFirstChild($doc, $heading);
        $tape->linkNextSibling($heading, $code);
        $tape->linkNextSibling($code, $para);

        $tape->setEndOffset($doc, 60);
        $tape->setEndOffset($heading, 7);
        $tape->setEndOffset($code, 39);
        $tape->setEndOffset($para, 60);

        $tape->setFlags($heading, 1);
        $tape->addFlags($code, 2);
        $tape->setPayload($code, 'php');

        return $tape;
    }
}
