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

final class BackslashEscapeParserTest extends TestCase
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

    public function testBackslashBeforePunctuationEscapesIt(): void
    {
        $nodes = self::nodes('\\!', [[0, 2, 0]]);

        self::assertSame([[InlineKind::TEXT, '\\!', '!']], $nodes);
    }

    public function testBackslashBeforeNonPunctuationIsLiteral(): void
    {
        $nodes = self::nodes('\\A', [[0, 2, 0]]);

        self::assertSame([[InlineKind::TEXT, '\\A', null]], $nodes);
    }

    public function testEscapedBackslashLeavesFollowingCharacterUnescaped(): void
    {
        // \\* : the first backslash escapes the second; the star stays literal.
        $nodes = self::nodes('\\\\*', [[0, 3, 0]]);

        self::assertSame([
            [InlineKind::TEXT, '\\\\', '\\'],
            [InlineKind::TEXT, '*', null],
        ], $nodes);
    }

    public function testTrailingBackslashAtEndOfContentIsLiteral(): void
    {
        $nodes = self::nodes('x\\', [[0, 2, 0]]);

        self::assertSame([[InlineKind::TEXT, 'x\\', null]], $nodes);
    }

    public function testEveryAsciiPunctuationIsEscapable(): void
    {
        $punctuation = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

        foreach (str_split($punctuation) as $char) {
            $source = '\\'.$char;
            $nodes = self::nodes($source, [[0, \strlen($source), 0]]);

            self::assertSame([[InlineKind::TEXT, $source, $char]], $nodes, 'escape of '.$char);
        }
    }
}
