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

use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\Block\ContinueResult;
use Alto\Markdown\Parser\Block\LinkReferenceDefinitionParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises extractInto() directly against a hand-built paragraph node, the
 * same shape the block loop hands it when a paragraph closes: the paragraph
 * spans from the first non-whitespace byte of its first line to the end of its
 * last line (no trailing line ending).
 */
final class LinkReferenceDefinitionParserTest extends TestCase
{
    public function testBlockConstructContractIsPassive(): void
    {
        [, , , , $state] = $this->stateFor('');
        $parser = new LinkReferenceDefinitionParser();

        self::assertSame(BlockKind::LINK_REFERENCE_DEFINITION, $parser->kind());
        self::assertSame('', $parser->triggerBytes());
        self::assertNull($parser->tryStart($state, 0, false));
        self::assertSame(ContinueResult::NotMatched, $parser->tryContinue($state, 0));

        $parser->close($state, 0);
        self::addToAssertionCount(1);
    }

    public function testEmptyParagraphIsLeftUntouched(): void
    {
        [, $tape, $document, $map, $result, $paragraph] = $this->extractBounded('', 0, 0);

        self::assertSame($paragraph, $result);
        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testLeadingThreeSpacesAreAcceptedAtTheParagraphBoundary(): void
    {
        $source = '   [foo]: /url';
        [, , , $map] = $this->extractBounded($source, 0, \strlen($source));

        self::assertSame(['destination' => '/url', 'title' => null], $map->lookup('foo'));
    }

    public function testSingleDefinitionEmitsNodeAndClearsParagraph(): void
    {
        [, $tape, $document, $map, $result] = $this->extract('[foo]: /url');

        self::assertSame([BlockKind::LINK_REFERENCE_DEFINITION], $this->childKinds($tape, $document));
        self::assertSame($tape->firstChildOrdinal($document), $result);
        self::assertSame(['destination' => '/url', 'title' => null], $map->lookup('foo'));
    }

    public function testDefinitionWithTitle(): void
    {
        [, , , $map] = $this->extract('[foo]: /url "the title"');

        self::assertSame(['destination' => '/url', 'title' => 'the title'], $map->lookup('foo'));
    }

    public function testLeftoverTextBecomesParagraph(): void
    {
        [$buffer, $tape, $document, $map, $result] = $this->extract("[foo]: /url\nbar");

        self::assertSame([BlockKind::LINK_REFERENCE_DEFINITION, BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
        self::assertTrue($map->has('foo'));

        $paragraph = $tape->nextSiblingOrdinal($tape->firstChildOrdinal($document));
        self::assertSame($paragraph, $result);
        self::assertSame('bar', $buffer->substring($tape->startOffset($paragraph), $tape->endOffset($paragraph)));
    }

    public function testMultilineLabelThenLeftover(): void
    {
        // Spec: "[\nfoo\n]: /url\nbar" defines [foo] and leaves "bar".
        [$buffer, $tape, $document, $map] = $this->extract("[\nfoo\n]: /url\nbar");

        self::assertTrue($map->has('foo'));
        self::assertSame([BlockKind::LINK_REFERENCE_DEFINITION, BlockKind::PARAGRAPH], $this->childKinds($tape, $document));

        $paragraph = $tape->nextSiblingOrdinal($tape->firstChildOrdinal($document));
        self::assertSame('bar', $buffer->substring($tape->startOffset($paragraph), $tape->endOffset($paragraph)));
    }

    public function testDefinitionCannotInterruptParagraph(): void
    {
        [, $tape, $document, $map, $result, $paragraph] = $this->extract("Foo\n[bar]: /baz");

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
        self::assertSame($paragraph, $result);
    }

    public function testStackedDefinitions(): void
    {
        [, $tape, $document, $map] = $this->extract("[a]: /1\n[b]: /2\n[c]: /3");

        self::assertSame(
            [BlockKind::LINK_REFERENCE_DEFINITION, BlockKind::LINK_REFERENCE_DEFINITION, BlockKind::LINK_REFERENCE_DEFINITION],
            $this->childKinds($tape, $document),
        );
        self::assertTrue($map->has('a'));
        self::assertTrue($map->has('b'));
        self::assertTrue($map->has('c'));
    }

    public function testStackedDefinitionWithTitleOnContinuationLine(): void
    {
        [, , , $map] = $this->extract("[foo]: /foo-url \"foo\"\n[bar]: /bar-url\n  \"bar\"\n[baz]: /baz-url");

        self::assertSame(['destination' => '/foo-url', 'title' => 'foo'], $map->lookup('foo'));
        self::assertSame(['destination' => '/bar-url', 'title' => 'bar'], $map->lookup('bar'));
        self::assertSame(['destination' => '/baz-url', 'title' => null], $map->lookup('baz'));
    }

    public function testFirstDefinitionWinsWithinOneBlock(): void
    {
        [, $tape, $document, $map] = $this->extract("[a]: /1\n[a]: /2");

        // Two definition nodes are recorded, but the first destination wins.
        self::assertSame(
            [BlockKind::LINK_REFERENCE_DEFINITION, BlockKind::LINK_REFERENCE_DEFINITION],
            $this->childKinds($tape, $document),
        );
        self::assertSame('/1', $map->lookup('a')['destination'] ?? null);
        self::assertSame(1, $map->count());
    }

    public function testUpToThreeSpacesIndentAllowedOnStackedDefinition(): void
    {
        [, , , $map] = $this->extract("[a]: /1\n  [b]: /2");

        self::assertTrue($map->has('a'));
        self::assertTrue($map->has('b'));
    }

    public function testFourSpacesIndentStopsExtraction(): void
    {
        [$buffer, $tape, $document, $map] = $this->extract("[a]: /1\n    [b]: /2");

        self::assertTrue($map->has('a'));
        self::assertFalse($map->has('b'));
        self::assertSame([BlockKind::LINK_REFERENCE_DEFINITION, BlockKind::PARAGRAPH], $this->childKinds($tape, $document));

        $paragraph = $tape->nextSiblingOrdinal($tape->firstChildOrdinal($document));
        self::assertSame('    [b]: /2', $buffer->substring($tape->startOffset($paragraph), $tape->endOffset($paragraph)));
    }

    public function testMissingDestinationIsNotDefinition(): void
    {
        [, $tape, $document, $map, $result, $paragraph] = $this->extract('[foo]:');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
        self::assertSame($paragraph, $result);
    }

    public function testCharactersAfterTitleRejectDefinition(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo]: /url "title" ok');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testTitleOnNextLineWithTrailingTextIsDroppedButDefinitionStands(): void
    {
        // Spec: destination line ends, so [foo] is defined without a title and
        // the "title" line stays as a paragraph.
        [$buffer, $tape, $document, $map] = $this->extract("[foo]: /url\n\"title\" ok");

        self::assertSame(['destination' => '/url', 'title' => null], $map->lookup('foo'));
        self::assertSame([BlockKind::LINK_REFERENCE_DEFINITION, BlockKind::PARAGRAPH], $this->childKinds($tape, $document));

        $paragraph = $tape->nextSiblingOrdinal($tape->firstChildOrdinal($document));
        self::assertSame('"title" ok', $buffer->substring($tape->startOffset($paragraph), $tape->endOffset($paragraph)));
    }

    public function testUnterminatedTitleRejectsDefinition(): void
    {
        // The paragraph is a single line; the title quote never closes.
        [, $tape, $document, $map] = $this->extract("[foo]: /url 'title");

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testAngleBracketedEmptyDestination(): void
    {
        [, , , $map] = $this->extract('[foo]: <>');

        self::assertSame(['destination' => '', 'title' => null], $map->lookup('foo'));
    }

    public function testAngleBracketedDestinationSpanningLinesWithTitle(): void
    {
        // Spec: "[Foo bar]:\n<my url>\n'title'".
        [, $tape, $document, $map] = $this->extract("[Foo bar]:\n<my url>\n'title'");

        self::assertSame(['destination' => 'my url', 'title' => 'title'], $map->lookup('foo bar'));
        self::assertSame([BlockKind::LINK_REFERENCE_DEFINITION], $this->childKinds($tape, $document));
    }

    public function testTitleMustBeSeparatedFromDestination(): void
    {
        // Spec: "[foo]: <bar>(baz)" is not a definition.
        [, $tape, $document, $map] = $this->extract('[foo]: <bar>(baz)');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testDefinitionLinksAfterExistingSibling(): void
    {
        [, , , $tape, $state] = $this->stateFor('[foo]: /url');
        $document = $tape->allocate(BlockKind::DOCUMENT, ParseTape::NONE, 0, 0);

        // A prior sibling (a heading, say) already sits under the document.
        $heading = $tape->allocate(BlockKind::ATX_HEADING, $document, 0, 0);
        $tape->linkFirstChild($document, $heading);

        $paragraph = $tape->allocate(BlockKind::PARAGRAPH, $document, 0, 11);
        $tape->linkNextSibling($heading, $paragraph);
        $tape->setEndOffset($paragraph, 11);

        $map = new ReferenceMap();
        $result = new LinkReferenceDefinitionParser()->extractInto($state, $map, $paragraph, $document, $heading);

        self::assertSame([BlockKind::ATX_HEADING, BlockKind::LINK_REFERENCE_DEFINITION], $this->childKinds($tape, $document));
        self::assertSame($tape->nextSiblingOrdinal($heading), $result);
        self::assertTrue($map->has('foo'));
    }

    public function testNestedParenthesesInDestination(): void
    {
        [, , , $map] = $this->extract('[foo]: /url(a(b)c)');

        self::assertSame(['destination' => '/url(a(b)c)', 'title' => null], $map->lookup('foo'));
    }

    public function testUnbalancedParenthesisRejectsDestination(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo]: /url(a');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testAngleDestinationWithNestedOpenerRejectsDefinition(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo]: <a<b>');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testEscapedBracketsInsideLabel(): void
    {
        [, , , $map] = $this->extract('[fo\\]o]: /url');

        self::assertSame(['destination' => '/url', 'title' => null], $map->lookup('fo\\]o'));
    }

    public function testTrailingBackslashInLabelLeavesNoDefinition(): void
    {
        // The backslash is the paragraph's last byte, so no closing bracket
        // remains and the scan must stop at the paragraph end.
        [, $tape, $document, $map] = $this->extract('[foo\\');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testUnterminatedLabelLeavesNoDefinition(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testBlankLabelLeavesNoDefinition(): void
    {
        [, $tape, $document, $map] = $this->extract('[   ]: /url');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testTrailingBackslashIsPartOfDestination(): void
    {
        [, , , $map] = $this->extract('[foo]: /url\\');

        self::assertSame(['destination' => '/url\\', 'title' => null], $map->lookup('foo'));
    }

    public function testTrailingBackslashLeavesTitleUnterminated(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo]: /url "t\\');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testEscapedClosingAngleCanAppearInDestination(): void
    {
        [, , , $map] = $this->extract('[foo]: <a\\>b>');

        self::assertSame(['destination' => 'a\\>b', 'title' => null], $map->lookup('foo'));
    }

    public function testUnterminatedAngleDestinationLeavesNoDefinition(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo]: <url');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testTrailingBackslashLeavesAngleDestinationUnterminated(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo]: <url\\');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testUnmatchedClosingParenthesisEndsBareDestination(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo]: url)');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testEscapedByteCanAppearInBareDestination(): void
    {
        [, , , $map] = $this->extract('[foo]: /u\\(rl');

        self::assertSame(['destination' => '/u\\(rl', 'title' => null], $map->lookup('foo'));
    }

    public function testParenthesizedTitleCannotNestAnOpeningParenthesis(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo]: /url (a(b)');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testEscapedDelimiterCanAppearInTitle(): void
    {
        [, , , $map] = $this->extract('[foo]: /url "a\\"b"');

        self::assertSame(['destination' => '/url', 'title' => 'a\\"b'], $map->lookup('foo'));
    }

    public function testTitleEndingAfterAnEscapedByteIsUnterminated(): void
    {
        [, $tape, $document, $map] = $this->extract('[foo]: /url "a\\b');

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    public function testCrLfSeparatesStackedDefinitions(): void
    {
        [, , , $map] = $this->extract("[a]: /1\r\n[b]: /2");

        self::assertSame('/1', $map->lookup('a')['destination'] ?? null);
        self::assertSame('/2', $map->lookup('b')['destination'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function timedMalformedDefinitions(): iterable
    {
        yield 'malformed label' => ['[a[b]: /url'];
        yield 'missing colon' => ['[foo] /url'];
        yield 'missing destination' => ['[foo]:'];
        yield 'invalid tail' => ['[foo]: /url "unterminated'];
        yield 'leftover non-definition' => ["[ok]: /url\nplain"];
    }

    #[DataProvider('timedMalformedDefinitions')]
    public function testMalformedDefinitionTimingCountsBytesActuallyScanned(string $source): void
    {
        Instrumentation::measure();

        try {
            $this->extract($source);
        } finally {
            Instrumentation::disable();
        }

        self::assertGreaterThan(0, Instrumentation::$blockRefdefScans);
        self::assertGreaterThan(0, Instrumentation::$blockRefdefScanBytes);
    }

    public function testScanStopsAtParagraphEndNotBufferEnd(): void
    {
        // The scanner reads the shared source buffer, so a definition must not
        // absorb bytes that belong to a later block.
        [$buffer, $tape, $document, $map, $result] = $this->extractBounded("[foo]: /url\n\n[bar]: /baz\n", 0, 11);

        self::assertSame(['destination' => '/url', 'title' => null], $map->lookup('foo'));
        self::assertFalse($map->has('bar'));
        self::assertSame([BlockKind::LINK_REFERENCE_DEFINITION], $this->childKinds($tape, $document));
        self::assertSame(11, $tape->endOffset($result));
        self::assertSame('[foo]: /url', $buffer->substring($tape->startOffset($result), $tape->endOffset($result)));
    }

    public function testDestinationIsCutAtParagraphEnd(): void
    {
        // "/url" runs to the paragraph end; the "xyz" after it belongs to a
        // later block and must not join the destination.
        [, , , $map] = $this->extractBounded('[foo]: /urlxyz', 0, 11);

        self::assertSame(['destination' => '/url', 'title' => null], $map->lookup('foo'));
    }

    public function testTitleIsNotFoundPastParagraphEnd(): void
    {
        // The closing quote sits past the paragraph end, so the title is
        // unterminated and the definition is rejected.
        [, $tape, $document, $map] = $this->extractBounded('[foo]: /url "title"', 0, 18);

        self::assertSame(0, $map->count());
        self::assertSame([BlockKind::PARAGRAPH], $this->childKinds($tape, $document));
    }

    /**
     * Builds the parser state and a paragraph node spanning the whole input,
     * runs extraction, and returns the pieces used by the assertions.
     *
     * @return array{SourceBuffer, ParseTape, int, ReferenceMap, int, int}
     */
    private function extract(string $markdown): array
    {
        [$buffer, , , $tape, $state] = $this->stateFor($markdown);
        $document = $tape->allocate(BlockKind::DOCUMENT, ParseTape::NONE, 0, 0);

        $start = 0;
        $length = \strlen($markdown);

        while ($start < $length && (' ' === $markdown[$start] || "\t" === $markdown[$start])) {
            ++$start;
        }

        $end = str_ends_with($markdown, "\n") ? $length - 1 : $length;

        $paragraph = $tape->allocate(BlockKind::PARAGRAPH, $document, $start, 0);
        $tape->linkFirstChild($document, $paragraph);
        $tape->setEndOffset($paragraph, $end);

        $map = new ReferenceMap();
        $result = new LinkReferenceDefinitionParser()->extractInto($state, $map, $paragraph, $document, ParseTape::NONE);

        return [$buffer, $tape, $document, $map, $result, $paragraph];
    }

    /**
     * Same as extract(), but the paragraph covers only [$start, $end) of a
     * larger buffer. Proves the scanner honours the paragraph's bounds rather
     * than the buffer's.
     *
     * @return array{SourceBuffer, ParseTape, int, ReferenceMap, int, int}
     */
    private function extractBounded(string $markdown, int $start, int $end): array
    {
        [$buffer, , , $tape, $state] = $this->stateFor($markdown);
        $document = $tape->allocate(BlockKind::DOCUMENT, ParseTape::NONE, 0, 0);

        $paragraph = $tape->allocate(BlockKind::PARAGRAPH, $document, $start, 0);
        $tape->linkFirstChild($document, $paragraph);
        $tape->setEndOffset($paragraph, $end);

        $map = new ReferenceMap();
        $result = new LinkReferenceDefinitionParser()->extractInto($state, $map, $paragraph, $document, ParseTape::NONE);

        return [$buffer, $tape, $document, $map, $result, $paragraph];
    }

    /**
     * @return array{SourceBuffer, LineScanner, LineMap, ParseTape, ParserState}
     */
    private function stateFor(string $markdown): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $lineMap = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $lineMap, $tape);

        return [$buffer, $scanner, $lineMap, $tape, $state];
    }

    /**
     * @return list<int>
     */
    private function childKinds(ParseTape $tape, int $parent): array
    {
        $kinds = [];
        $child = $tape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $child) {
            $kinds[] = $tape->kindId($child);
            $child = $tape->nextSiblingOrdinal($child);
        }

        return $kinds;
    }
}
