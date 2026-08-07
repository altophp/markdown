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
use PHPUnit\Framework\TestCase;

final class CodeSpanParserTest extends TestCase
{
    /**
     * Each node as [kind, payload ?? source slice], mirroring how the test
     * renderer reads a code span's processed content from its payload.
     *
     * @param list<array{int, int, int}> $pairs
     *
     * @return list<array{int, string}>
     */
    private static function nodes(string $source, array $pairs): array
    {
        $buffer = new SourceBuffer($source);
        $tape = new InlineParser()->parse($buffer, $pairs, new ReferenceMap());
        $out = [];
        $node = $tape->firstChildOrdinal(0);

        while (ParseTape::NONE !== $node) {
            $slice = $buffer->substring($tape->startOffset($node), $tape->endOffset($node));
            $out[] = [$tape->kindId($node), $tape->payload($node) ?? $slice];
            $node = $tape->nextSiblingOrdinal($node);
        }

        return $out;
    }

    public function testSimpleSpan(): void
    {
        $nodes = self::nodes('`foo`', [[0, 5, 0]]);

        self::assertSame([[InlineKind::CODE_SPAN, 'foo']], $nodes);
    }

    public function testBacktickInsideDoubleRun(): void
    {
        $nodes = self::nodes('`` foo ` bar ``', [[0, 15, 0]]);

        self::assertSame([[InlineKind::CODE_SPAN, 'foo ` bar']], $nodes);
    }

    public function testOneSpaceStrippedFromEachEnd(): void
    {
        $nodes = self::nodes('` `` `', [[0, 6, 0]]);

        self::assertSame([[InlineKind::CODE_SPAN, '``']], $nodes);
    }

    public function testOnlyOneSpaceIsStripped(): void
    {
        $nodes = self::nodes('`  ``  `', [[0, 8, 0]]);

        self::assertSame([[InlineKind::CODE_SPAN, ' `` ']], $nodes);
    }

    public function testStrippingNeedsSpaceOnBothSides(): void
    {
        $nodes = self::nodes('` a`', [[0, 4, 0]]);

        self::assertSame([[InlineKind::CODE_SPAN, ' a']], $nodes);
    }

    public function testAllSpaceContentIsNotStripped(): void
    {
        $nodes = self::nodes('`  `', [[0, 4, 0]]);

        self::assertSame([[InlineKind::CODE_SPAN, '  ']], $nodes);
    }

    public function testLongerRunDoesNotClose(): void
    {
        $nodes = self::nodes('` foo `` bar `', [[0, 14, 0]]);

        self::assertSame([[InlineKind::CODE_SPAN, 'foo `` bar']], $nodes);
    }

    public function testBackslashStaysLiteral(): void
    {
        $nodes = self::nodes('`foo\\`bar`', [[0, 10, 0]]);

        self::assertSame([
            [InlineKind::CODE_SPAN, 'foo\\'],
            [InlineKind::TEXT, 'bar`'],
        ], $nodes);
    }

    public function testUnmatchedRunStaysLiteral(): void
    {
        // ```foo`` -- no run of exactly three backticks closes the opener,
        // and the inner two-run must not open a fresh span either.
        $nodes = self::nodes('```foo``', [[0, 8, 0]]);

        self::assertSame([[InlineKind::TEXT, '```foo``']], $nodes);
    }

    public function testUnequalLengthLeavesLeadingBackticksLiteral(): void
    {
        $nodes = self::nodes('`foo``bar``', [[0, 11, 0]]);

        self::assertSame([
            [InlineKind::TEXT, '`foo'],
            [InlineKind::CODE_SPAN, 'bar'],
        ], $nodes);
    }

    public function testLineEndingBecomesSpace(): void
    {
        // Content spans a joint: "`a" + joint + "b`" -> code content "a b".
        $nodes = self::nodes("`a\nb`", [[0, 2, 0], [3, 5, 0]]);

        self::assertSame([[InlineKind::CODE_SPAN, 'a b']], $nodes);
    }

    public function testTextPrecedingSpanIsSeparate(): void
    {
        $nodes = self::nodes('x`y`', [[0, 4, 0]]);

        self::assertSame([
            [InlineKind::TEXT, 'x'],
            [InlineKind::CODE_SPAN, 'y'],
        ], $nodes);
    }
}
