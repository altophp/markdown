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

namespace Alto\Markdown\Tests\Source;

use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Source\LineEnding;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineScanner::class)]
final class LineScannerTest extends TestCase
{
    private static function scan(string $input): LineScanner
    {
        return new LineScanner(new SourceBuffer($input));
    }

    public function testEmptyInputHasNoLinesAndDefaultsToLf(): void
    {
        $scanner = self::scan('');

        self::assertSame(0, $scanner->lineCount());
        self::assertSame(LineEnding::Lf, $scanner->dominantEol());
    }

    public function testSingleLineWithoutTrailingEol(): void
    {
        $scanner = self::scan('abc');

        self::assertSame(1, $scanner->lineCount());
        self::assertSame(0, $scanner->contentStart(0));
        self::assertSame(3, $scanner->contentEnd(0));
        self::assertSame(3, $scanner->lineEnd(0));
        self::assertNull($scanner->eol(0));
        self::assertSame(LineEnding::Lf, $scanner->dominantEol());
    }

    public function testTrailingNewlineDoesNotCreatePhantomLine(): void
    {
        $scanner = self::scan("a\n");

        self::assertSame(1, $scanner->lineCount());
        self::assertSame(LineEnding::Lf, $scanner->eol(0));
        self::assertSame(1, $scanner->contentEnd(0));
        self::assertSame(2, $scanner->lineEnd(0));
    }

    public function testBlankLineBetweenContent(): void
    {
        $scanner = self::scan("a\n\nb");

        self::assertSame(3, $scanner->lineCount());
        self::assertSame(2, $scanner->contentStart(1));
        self::assertSame(2, $scanner->contentEnd(1));
        self::assertSame(LineEnding::Lf, $scanner->eol(1));
        self::assertNull($scanner->eol(2));
    }

    public function testCrLfSplitsContentEndFromLineEnd(): void
    {
        $scanner = self::scan("ab\r\ncd");

        self::assertSame(2, $scanner->lineCount());
        self::assertSame(2, $scanner->contentEnd(0));
        self::assertSame(4, $scanner->lineEnd(0));
        self::assertSame(LineEnding::CrLf, $scanner->eol(0));
        self::assertSame(4, $scanner->contentStart(1));
        self::assertSame('cd', (new SourceBuffer("ab\r\ncd"))->slice($scanner->contentRange(1)));
    }

    public function testLoneCarriageReturn(): void
    {
        $scanner = self::scan("ab\rcd");

        self::assertSame(2, $scanner->lineCount());
        self::assertSame(2, $scanner->contentEnd(0));
        self::assertSame(3, $scanner->lineEnd(0));
        self::assertSame(LineEnding::Cr, $scanner->eol(0));
        self::assertSame(3, $scanner->contentStart(1));
    }

    public function testBomShiftsFirstLineContentStart(): void
    {
        $scanner = self::scan("\xEF\xBB\xBFabc\ndef");

        self::assertSame(2, $scanner->lineCount());
        self::assertSame(3, $scanner->contentStart(0));
        self::assertSame(6, $scanner->contentEnd(0));
        self::assertSame(7, $scanner->lineEnd(0));
        self::assertSame(LineEnding::Lf, $scanner->eol(0));
        self::assertSame(7, $scanner->contentStart(1));
        self::assertEquals(new SourceRange(3, 6), $scanner->contentRange(0));
    }

    public function testMixedEolsPickDominantByFrequency(): void
    {
        // three CrLf, one Lf
        $scanner = self::scan("a\r\nb\r\nc\r\nd\ne");

        self::assertSame(5, $scanner->lineCount());
        self::assertSame(LineEnding::CrLf, $scanner->dominantEol());
    }

    public function testCarriageReturnMajorityIsDominant(): void
    {
        // two Cr, one Lf
        $scanner = self::scan("a\rb\rc\nd");

        self::assertSame(LineEnding::Cr, $scanner->dominantEol());
    }

    #[DataProvider('provideDominantTies')]
    public function testTiesResolveTowardLf(string $input, LineEnding $expected): void
    {
        self::assertSame($expected, self::scan($input)->dominantEol());
    }

    /**
     * @return iterable<string, array{string, LineEnding}>
     */
    public static function provideDominantTies(): iterable
    {
        yield 'lf and crlf tie prefers lf' => ["a\nb\r\nc", LineEnding::Lf];
        yield 'lf and cr tie prefers lf' => ["a\nb\rc", LineEnding::Lf];
        yield 'crlf and cr tie prefers crlf' => ["a\r\nb\rc", LineEnding::CrLf];
        yield 'all three tie prefers lf' => ["a\nb\r\nc\rd", LineEnding::Lf];
    }

    public function testAccessorsRejectOutOfRangeLine(): void
    {
        $scanner = self::scan('abc');

        $this->expectException(\OutOfRangeException::class);

        $scanner->contentStart(1);
    }

    public function testNegativeLineIndexIsRejected(): void
    {
        $scanner = self::scan('abc');

        $this->expectException(\OutOfRangeException::class);

        $scanner->eol(-1);
    }

    public function testRemainingAccessorsRejectInvalidIndexes(): void
    {
        $scanner = self::scan('abc');

        foreach ([
            static fn(): int => $scanner->contentEnd(1),
            static fn(): int => $scanner->lineEnd(-1),
            static fn(): SourceRange => $scanner->contentRange(1),
        ] as $read) {
            try {
                $read();
                self::fail('Expected the invalid line index to fail.');
            } catch (\OutOfRangeException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
