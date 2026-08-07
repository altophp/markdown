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

use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntityReferenceParserTest extends TestCase
{
    /**
     * @param list<array{int, int, int}> $pairs
     *
     * @return list<array{int, string, ?string}>
     */
    private static function nodes(string $source, array $pairs): array
    {
        $buffer = new SourceBuffer($source);
        $tape = new InlineParser()->parse($buffer, $pairs, new ReferenceMap());
        $out = [];
        $node = $tape->firstChildOrdinal(0);

        while (ParseTape::NONE !== $node) {
            $out[] = [
                $tape->kindId($node),
                $buffer->substring($tape->startOffset($node), $tape->endOffset($node)),
                $tape->payload($node),
            ];
            $node = $tape->nextSiblingOrdinal($node);
        }

        return $out;
    }

    /**
     * @return list<array{int, string, ?string}>
     */
    private static function scan(string $source): array
    {
        return self::nodes($source, [[0, \strlen($source), 0]]);
    }

    public function testNamedEntityDecodesToUtf8(): void
    {
        self::assertSame([[InlineKind::TEXT, '&amp;', '&']], self::scan('&amp;'));
        self::assertSame([[InlineKind::TEXT, '&nbsp;', "\u{00A0}"]], self::scan('&nbsp;'));
        self::assertSame([[InlineKind::TEXT, '&copy;', "\u{00A9}"]], self::scan('&copy;'));
    }

    public function testNamedEntityWithMultipleCodepoints(): void
    {
        self::assertSame([[InlineKind::TEXT, '&ngE;', "\u{2267}\u{0338}"]], self::scan('&ngE;'));
    }

    public function testDecimalReference(): void
    {
        self::assertSame([[InlineKind::TEXT, '&#35;', '#']], self::scan('&#35;'));
        self::assertSame([[InlineKind::TEXT, '&#1234;', "\u{04D2}"]], self::scan('&#1234;'));
    }

    public function testHexReferenceIsCaseInsensitiveOnTheMarker(): void
    {
        self::assertSame([[InlineKind::TEXT, '&#X22;', '"']], self::scan('&#X22;'));
        self::assertSame([[InlineKind::TEXT, '&#xcab;', "\u{0CAB}"]], self::scan('&#xcab;'));
    }

    public function testZeroAndOutOfRangeBecomeReplacementCharacter(): void
    {
        self::assertSame([[InlineKind::TEXT, '&#0;', "\u{FFFD}"]], self::scan('&#0;'));
        self::assertSame([[InlineKind::TEXT, '&#1114112;', "\u{FFFD}"]], self::scan('&#1114112;'));
        self::assertSame([[InlineKind::TEXT, '&#xD800;', "\u{FFFD}"]], self::scan('&#xD800;'));
    }

    public function testControlCodepointsDecodeLiterally(): void
    {
        self::assertSame([[InlineKind::TEXT, '&#10;', "\n"]], self::scan('&#10;'));
        self::assertSame([[InlineKind::TEXT, '&#9;', "\t"]], self::scan('&#9;'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonEntities(): iterable
    {
        yield 'no semicolon' => ['&nbsp'];
        yield 'unknown name with semicolon' => ['&x;'];
        yield 'empty numeric' => ['&#;'];
        yield 'empty hex' => ['&#x;'];
        yield 'too many decimal digits' => ['&#87654321;'];
        yield 'non-hex after marker' => ['&#abcdef0;'];
        yield 'made up entity' => ['&MadeUpEntity;'];
        yield 'named without semicolon' => ['&copy'];
        yield 'question mark breaks name' => ['&hi?;'];
    }

    #[DataProvider('nonEntities')]
    public function testNonEntityStaysLiteral(string $source): void
    {
        self::assertSame([[InlineKind::TEXT, $source, null]], self::scan($source));
    }

    public function testEntityFlushesSurroundingText(): void
    {
        self::assertSame([
            [InlineKind::TEXT, 'a', null],
            [InlineKind::TEXT, '&amp;', '&'],
            [InlineKind::TEXT, 'b', null],
        ], self::scan('a&amp;b'));
    }

    public function testIncompleteAndFourByteReferencesCoverTheBoundaries(): void
    {
        self::assertSame([[InlineKind::TEXT, '&', null]], self::scan('&'));
        self::assertSame([[InlineKind::TEXT, '&#12', null]], self::scan('&#12'));
        self::assertSame([[InlineKind::TEXT, '&#128512;', "\u{1F600}"]], self::scan('&#128512;'));
    }
}
