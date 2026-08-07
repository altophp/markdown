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

use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineMap::class)]
final class LineMapTest extends TestCase
{
    private static function mapOf(string $input): LineMap
    {
        $buffer = new SourceBuffer($input);

        return new LineMap($buffer, new LineScanner($buffer));
    }

    public function testNoIndent(): void
    {
        $map = self::mapOf('foo');

        self::assertSame(0, $map->firstNonSpaceOffset(0));
        self::assertSame(0, $map->firstNonSpaceColumn(0));
        self::assertFalse($map->isBlank(0));
    }

    /**
     * @param non-empty-string $input
     */
    #[DataProvider('provideTabStops')]
    public function testTabStopColumns(string $input, int $expectedOffset, int $expectedColumn): void
    {
        $map = self::mapOf($input);

        self::assertSame($expectedOffset, $map->firstNonSpaceOffset(0));
        self::assertSame($expectedColumn, $map->firstNonSpaceColumn(0));
        self::assertFalse($map->isBlank(0));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function provideTabStops(): iterable
    {
        yield 'tab at column zero' => ["\tfoo", 1, 4];
        yield 'one space then tab' => [" \tfoo", 2, 4];
        yield 'two spaces then tab' => ["  \tfoo", 3, 4];
        yield 'three spaces then tab' => ["   \tfoo", 4, 4];
        yield 'four spaces reach column four' => ['    foo', 4, 4];
        yield 'consecutive tabs' => ["\t\tfoo", 2, 8];
        yield 'space after full tab stop' => ["\t foo", 2, 5];
    }

    /**
     * @param non-empty-string $line
     */
    #[DataProvider('provideBlankLines')]
    public function testBlankLineDetection(string $line, int $expectedColumn): void
    {
        // Wrap the blank line between two content lines so offsets are non-trivial.
        $map = self::mapOf("x\n".$line."\ny");

        self::assertTrue($map->isBlank(1));
        self::assertSame($expectedColumn, $map->firstNonSpaceColumn(1));
        self::assertFalse($map->isBlank(0));
        self::assertFalse($map->isBlank(2));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideBlankLines(): iterable
    {
        yield 'spaces only' => ['   ', 3];
        yield 'tabs only' => ["\t\t", 8];
        yield 'space tab space mix' => [" \t ", 5];
    }

    public function testEmptyLineIsBlankWithZeroColumn(): void
    {
        $map = self::mapOf("a\n\nb");

        self::assertTrue($map->isBlank(1));
        self::assertSame(0, $map->firstNonSpaceColumn(1));
        self::assertSame($map->firstNonSpaceOffset(1), 2);
    }

    public function testBlankFirstNonSpaceOffsetIsContentEnd(): void
    {
        $input = "x\n   \ny";
        $buffer = new SourceBuffer($input);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);

        self::assertTrue($map->isBlank(1));
        self::assertSame($scanner->contentEnd(1), $map->firstNonSpaceOffset(1));
    }

    public function testIndentColumnsWithBom(): void
    {
        $map = self::mapOf("\xEF\xBB\xBF\tfoo");

        self::assertSame(4, $map->firstNonSpaceOffset(0));
        self::assertSame(4, $map->firstNonSpaceColumn(0));
        self::assertFalse($map->isBlank(0));
    }

    public function testAccessorRejectsOutOfRangeLine(): void
    {
        $map = self::mapOf('foo');

        $this->expectException(\OutOfRangeException::class);

        $map->isBlank(1);
    }

    public function testLineCountAndOtherAccessorsRejectInvalidIndexes(): void
    {
        $map = self::mapOf('foo');

        self::assertSame(1, $map->lineCount());

        foreach ([
            static fn (): int => $map->firstNonSpaceOffset(-1),
            static fn (): int => $map->firstNonSpaceColumn(1),
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
