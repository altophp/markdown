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

use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceBuffer::class)]
final class SourceBufferTest extends TestCase
{
    public function testBomIsDetectedAndOffsetsIncludeIt(): void
    {
        $buffer = new SourceBuffer("\xEF\xBB\xBFabc");

        self::assertTrue($buffer->hasBom);
        self::assertSame(6, $buffer->length);
        self::assertSame(3, $buffer->contentStart());
        self::assertSame('abc', $buffer->slice(new SourceRange(3, 6)));
    }

    public function testNoBom(): void
    {
        $buffer = new SourceBuffer('abc');

        self::assertFalse($buffer->hasBom);
        self::assertSame(3, $buffer->length);
        self::assertSame(0, $buffer->contentStart());
    }

    public function testBomLikeSequenceAwayFromOffsetZeroIsContent(): void
    {
        $buffer = new SourceBuffer("a\xEF\xBB\xBF");

        self::assertFalse($buffer->hasBom);
        self::assertSame(0, $buffer->contentStart());
        self::assertSame(4, $buffer->length);
    }

    public function testEmptyInput(): void
    {
        $buffer = new SourceBuffer('');

        self::assertFalse($buffer->hasBom);
        self::assertSame(0, $buffer->length);
        self::assertSame(0, $buffer->contentStart());
    }

    public function testByteAtReturnsOrdinal(): void
    {
        $buffer = new SourceBuffer("\xEF\xBB\xBFa");

        self::assertSame(0xEF, $buffer->byteAt(0));
        self::assertSame(0xBB, $buffer->byteAt(1));
        self::assertSame(0xBF, $buffer->byteAt(2));
        self::assertSame(\ord('a'), $buffer->byteAt(3));
    }

    public function testByteAtOutOfRangeReturnsSentinel(): void
    {
        $buffer = new SourceBuffer('ab');

        self::assertSame(-1, $buffer->byteAt(-1));
        self::assertSame(-1, $buffer->byteAt(2));
        self::assertSame(-1, $buffer->byteAt(99));
    }

    public function testSliceIsStartInclusiveEndExclusive(): void
    {
        $buffer = new SourceBuffer('abcdef');

        self::assertSame('abc', $buffer->slice(new SourceRange(0, 3)));
        self::assertSame('cd', $buffer->slice(new SourceRange(2, 4)));
        self::assertSame('', $buffer->slice(new SourceRange(2, 2)));
    }

    public function testSubstringGuardsEmptyAndInvertedRanges(): void
    {
        $buffer = new SourceBuffer('abcdef');

        self::assertSame('', $buffer->substring(3, 3));
        self::assertSame('', $buffer->substring(4, 2));
        self::assertSame('ab', $buffer->substring(-5, 2));
    }
}
