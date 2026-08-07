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

final class AutolinkParserTest extends TestCase
{
    /**
     * Each inline node as [kind, source slice, payload]. An autolink keeps
     * the label in its slice and the href in its payload, and consumes the
     * angle brackets as empty-payload text markers.
     *
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

    private static function hasAutolink(string $source): bool
    {
        foreach (self::nodes($source, [[0, \strlen($source), 0]]) as [$kind]) {
            if (InlineKind::AUTOLINK === $kind) {
                return true;
            }
        }

        return false;
    }

    public function testUriAutolinkKeepsLabelInSliceAndHrefInPayload(): void
    {
        $nodes = self::nodes('<http://foo.bar.baz>', [[0, 20, 0]]);

        self::assertSame([
            [InlineKind::TEXT, '<', ''],
            [InlineKind::AUTOLINK, 'http://foo.bar.baz', 'http://foo.bar.baz'],
            [InlineKind::TEXT, '>', ''],
        ], $nodes);
    }

    public function testAmpersandStaysLiteralInHref(): void
    {
        // The renderer HTML-escapes the payload, so `&` must survive raw.
        $nodes = self::nodes('<https://x/?a&b>', [[0, 16, 0]]);

        self::assertSame(
            [InlineKind::AUTOLINK, 'https://x/?a&b', 'https://x/?a&b'],
            $nodes[1],
        );
    }

    public function testUppercaseMailtoIsUriAutolink(): void
    {
        $nodes = self::nodes('<MAILTO:FOO@BAR.BAZ>', [[0, 20, 0]]);

        self::assertSame(
            [InlineKind::AUTOLINK, 'MAILTO:FOO@BAR.BAZ', 'MAILTO:FOO@BAR.BAZ'],
            $nodes[1],
        );
    }

    public function testHrefPercentEncodesBackslashAndBrackets(): void
    {
        $nodes = self::nodes('<https://example.com/\\[\\>', [[0, 25, 0]]);

        self::assertSame(
            [InlineKind::AUTOLINK, 'https://example.com/\\[\\', 'https://example.com/%5C%5B%5C'],
            $nodes[1],
        );
    }

    public function testEmailAutolinkGetsMailtoHref(): void
    {
        $nodes = self::nodes('<foo@bar.example.com>', [[0, 21, 0]]);

        self::assertSame(
            [InlineKind::AUTOLINK, 'foo@bar.example.com', 'mailto:foo@bar.example.com'],
            $nodes[1],
        );
    }

    public function testEmailWithSpecialLocalPartAndSubdomains(): void
    {
        $nodes = self::nodes('<foo+special@Bar.baz-bar0.com>', [[0, 30, 0]]);

        self::assertSame(
            [InlineKind::AUTOLINK, 'foo+special@Bar.baz-bar0.com', 'mailto:foo+special@Bar.baz-bar0.com'],
            $nodes[1],
        );
    }

    public function testPrecedingTextIsASeparateNode(): void
    {
        $nodes = self::nodes('foo <http://x>', [[0, 14, 0]]);

        self::assertSame([
            [InlineKind::TEXT, 'foo ', null],
            [InlineKind::TEXT, '<', ''],
            [InlineKind::AUTOLINK, 'http://x', 'http://x'],
            [InlineKind::TEXT, '>', ''],
        ], $nodes);
    }

    public function testSchemeShorterThanTwoIsNotAutolink(): void
    {
        self::assertFalse(self::hasAutolink('<m:abc>'));
    }

    public function testMissingSchemeColonIsNotAutolink(): void
    {
        self::assertFalse(self::hasAutolink('<foo.bar.baz>'));
    }

    public function testSpaceInsideIsNotAutolink(): void
    {
        self::assertFalse(self::hasAutolink('<https://foo.bar/baz bim>'));
    }

    public function testEmptyBracketsAreNotAutolink(): void
    {
        self::assertFalse(self::hasAutolink('<>'));
    }

    public function testBackslashInEmailLocalPartIsRejected(): void
    {
        self::assertFalse(self::hasAutolink('<foo\\+@bar.example.com>'));
    }

    public function testAutolinkDoesNotCrossALineBreak(): void
    {
        // Two content lines joined by "\n": the joint is a control byte, so
        // the inner scan stops and the construct never matches.
        foreach (self::nodes("<http://\nx>", [[0, 8, 0], [9, 11, 0]]) as [$kind]) {
            self::assertNotSame(InlineKind::AUTOLINK, $kind);
        }
    }
}
