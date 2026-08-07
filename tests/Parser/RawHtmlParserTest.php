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

final class RawHtmlParserTest extends TestCase
{
    /**
     * Each inline node as [kind, source slice, payload]. A raw HTML tag
     * carries its verbatim content in the payload.
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

    private static function hasHtml(string $source): bool
    {
        foreach (self::nodes($source, [[0, \strlen($source), 0]]) as [$kind]) {
            if (InlineKind::HTML_INLINE === $kind) {
                return true;
            }
        }

        return false;
    }

    public function testAdjacentOpenTagsEachBecomeANode(): void
    {
        $nodes = self::nodes('<a><bab><c2c>', [[0, 13, 0]]);

        self::assertSame([
            [InlineKind::HTML_INLINE, '<a>', '<a>'],
            [InlineKind::HTML_INLINE, '<bab>', '<bab>'],
            [InlineKind::HTML_INLINE, '<c2c>', '<c2c>'],
        ], $nodes);
    }

    public function testEmptyElementTag(): void
    {
        $nodes = self::nodes('<a/>', [[0, 4, 0]]);

        self::assertSame([[InlineKind::HTML_INLINE, '<a/>', '<a/>']], $nodes);
    }

    public function testOpenTagWithAttributes(): void
    {
        $source = '<a foo="bar" _boolean zoop:33=zoop:33 />';
        $nodes = self::nodes($source, [[0, \strlen($source), 0]]);

        self::assertSame([[InlineKind::HTML_INLINE, $source, $source]], $nodes);
    }

    public function testClosingTagWithTrailingSpace(): void
    {
        $nodes = self::nodes('</foo >', [[0, 7, 0]]);

        self::assertSame([[InlineKind::HTML_INLINE, '</foo >', '</foo >']], $nodes);
    }

    public function testEmptyCommentForms(): void
    {
        self::assertSame(
            [[InlineKind::HTML_INLINE, '<!-->', '<!-->']],
            self::nodes('<!-->', [[0, 5, 0]]),
        );
        self::assertSame(
            [[InlineKind::HTML_INLINE, '<!--->', '<!--->']],
            self::nodes('<!--->', [[0, 6, 0]]),
        );
    }

    public function testCommentSpanningALineKeepsTheJointInThePayload(): void
    {
        // Two lines joined by "\n"; the comment payload is the content text,
        // so it carries the joint rather than the raw source line ending.
        $nodes = self::nodes("<!-- a\nb -->", [[0, 6, 0], [7, 12, 0]]);

        self::assertSame([[InlineKind::HTML_INLINE, "<!-- a\nb -->", "<!-- a\nb -->"]], $nodes);
    }

    public function testProcessingInstruction(): void
    {
        $source = '<?php echo $a; ?>';
        $nodes = self::nodes($source, [[0, \strlen($source), 0]]);

        self::assertSame([[InlineKind::HTML_INLINE, $source, $source]], $nodes);
    }

    public function testDeclaration(): void
    {
        $nodes = self::nodes('<!ELEMENT br EMPTY>', [[0, 19, 0]]);

        self::assertSame([[InlineKind::HTML_INLINE, '<!ELEMENT br EMPTY>', '<!ELEMENT br EMPTY>']], $nodes);
    }

    public function testCdataSection(): void
    {
        $nodes = self::nodes('<![CDATA[>&<]]>', [[0, 15, 0]]);

        self::assertSame([[InlineKind::HTML_INLINE, '<![CDATA[>&<]]>', '<![CDATA[>&<]]>']], $nodes);
    }

    public function testIllegalTagNameIsNotHtml(): void
    {
        self::assertFalse(self::hasHtml('<33>'));
        self::assertFalse(self::hasHtml('<__>'));
    }

    public function testIllegalAttributeNameIsNotHtml(): void
    {
        self::assertFalse(self::hasHtml('<a h*#ref="hi">'));
    }

    public function testMissingWhitespaceBetweenAttributesIsNotHtml(): void
    {
        self::assertFalse(self::hasHtml("<a href='bar'title=title>"));
    }

    public function testClosingTagWithAttributesIsNotHtml(): void
    {
        self::assertFalse(self::hasHtml('</a href="foo">'));
    }

    public function testSpaceAfterOpeningAngleIsNotHtml(): void
    {
        self::assertFalse(self::hasHtml('< a>'));
    }

    public function testUnterminatedCommentIsNotHtml(): void
    {
        self::assertFalse(self::hasHtml('<!-- no end'));
    }

    public function testOpenTagEndingInWhitespaceIsNotHtml(): void
    {
        // The attribute scan runs at end of string here; it must report no
        // attribute rather than read past the buffer.
        self::assertFalse(self::hasHtml('<b '));
    }

    public function testIncompleteRawHtmlFormsRemainText(): void
    {
        self::assertFalse(self::hasHtml('<'));
        self::assertFalse(self::hasHtml('</>'));
        self::assertFalse(self::hasHtml('<!1>'));
        self::assertFalse(self::hasHtml('<a b='));
    }
}
