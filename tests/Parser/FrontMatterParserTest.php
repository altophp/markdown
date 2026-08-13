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

use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Node\FrontMatter;
use Alto\Markdown\Parser\Block\FrontMatterParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrontMatterParserTest extends TestCase
{
    private static function github(string $markdown): MarkdownDocument
    {
        return Markdown::github()->fromString($markdown);
    }

    private static function frontMatter(string $markdown): FrontMatter
    {
        $frontMatter = self::github($markdown)->frontMatter();

        self::assertInstanceOf(FrontMatter::class, $frontMatter);

        return $frontMatter;
    }

    public function testYamlFenceOpensAnOpaqueBlock(): void
    {
        $frontMatter = self::frontMatter("---\ntitle: Hello\n---\n\nBody.\n");

        self::assertSame('frontmatter:block', $frontMatter->kind()->name);
        self::assertSame('---', $frontMatter->fence());
        self::assertSame("---\ntitle: Hello\n---\n", $frontMatter->text());
        self::assertSame("title: Hello\n", $frontMatter->content());
        self::assertSame(0, $frontMatter->range()->startOffset);
        self::assertSame(21, $frontMatter->range()->endOffset);
    }

    public function testTomlFenceOpensAnOpaqueBlock(): void
    {
        $frontMatter = self::frontMatter("+++\ntitle = \"Hello\"\n+++\n\nBody.\n");

        self::assertSame('+++', $frontMatter->fence());
        self::assertSame("+++\ntitle = \"Hello\"\n+++\n", $frontMatter->text());
        self::assertSame("title = \"Hello\"\n", $frontMatter->content());
    }

    public function testContentIsNeverDecoded(): void
    {
        // Bytes a YAML parser would reject stay exactly as written.
        $frontMatter = self::frontMatter("---\n  : [oops\n\ttab: \"unclosed\n---\nBody.\n");

        self::assertSame("  : [oops\n\ttab: \"unclosed\n", $frontMatter->content());
    }

    public function testEmptyFrontMatterHasEmptyContent(): void
    {
        $frontMatter = self::frontMatter("---\n---\nBody.\n");

        self::assertSame("---\n---\n", $frontMatter->text());
        self::assertSame('', $frontMatter->content());
    }

    public function testBlankLinesInsideTheBlockDoNotEndIt(): void
    {
        $frontMatter = self::frontMatter("---\na: 1\n\nb: 2\n---\nBody.\n");

        self::assertSame("a: 1\n\nb: 2\n", $frontMatter->content());
    }

    public function testTrailingWhitespaceOnFencesIsTolerated(): void
    {
        $frontMatter = self::frontMatter("--- \na: 1\n---\t\nBody.\n");

        self::assertSame("--- \na: 1\n---\t\n", $frontMatter->text());
        self::assertSame("a: 1\n", $frontMatter->content());
    }

    public function testBlockEndsAtTheFirstClosingFence(): void
    {
        $frontMatter = self::frontMatter("---\na: 1\n---\nBody.\n---\nMore.\n");

        self::assertSame("---\na: 1\n---\n", $frontMatter->text());
    }

    public function testUnterminatedBlockFallsBackToCommonMarkParsing(): void
    {
        $document = self::github("---\ntitle: Hello\n\nBody.\n");

        self::assertNull($document->frontMatter());
        self::assertSame("<hr />\n<p>title: Hello</p>\n<p>Body.</p>\n", $document->toHtml());
    }

    public function testMismatchedClosingFenceDoesNotTerminateTheBlock(): void
    {
        $document = self::github("+++\ntitle = \"Hello\"\n---\nBody.\n");

        self::assertNull($document->frontMatter());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonOpeningLines(): iterable
    {
        yield 'blank line before the fence' => ["\n---\na: 1\n---\n"];
        yield 'content before the fence' => ["# Title\n\n---\na: 1\n---\n"];
        yield 'indented fence' => [" ---\na: 1\n---\n"];
        yield 'content after the fence' => ["--- title\na: 1\n---\n"];
        yield 'four dashes' => ["----\na: 1\n----\n"];
        yield 'two dashes' => ["--\na: 1\n--\n"];
    }

    #[DataProvider('provideNonOpeningLines')]
    public function testLinesThatCannotOpenFrontMatter(string $markdown): void
    {
        self::assertNull(self::github($markdown)->frontMatter());
    }

    public function testFenceOnALaterLineStaysAThematicBreak(): void
    {
        $document = self::github("Intro.\n\n---\n\nOutro.\n\n---\n");

        self::assertNull($document->frontMatter());
        self::assertSame("<p>Intro.</p>\n<hr />\n<p>Outro.</p>\n<hr />\n", $document->toHtml());
    }

    public function testCrLfLineEndingsAreKeptVerbatim(): void
    {
        $frontMatter = self::frontMatter("---\r\ntitle: Hello\r\n---\r\nBody.\r\n");

        self::assertSame("---\r\ntitle: Hello\r\n---\r\n", $frontMatter->text());
        self::assertSame("title: Hello\r\n", $frontMatter->content());
    }

    public function testLoneCrLineEndingsAreKeptVerbatim(): void
    {
        $frontMatter = self::frontMatter("---\rtitle: Hello\r---\rBody.\r");

        self::assertSame("---\rtitle: Hello\r---\r", $frontMatter->text());
        self::assertSame("title: Hello\r", $frontMatter->content());
    }

    public function testBomBeforeTheFenceIsNotPartOfTheBlock(): void
    {
        $frontMatter = self::frontMatter("\u{FEFF}---\ntitle: Hello\n---\n# Heading\n");

        // SPEC section 9: offsets are original input bytes, so the block starts
        // at 3. The BOM belongs to the document, not to the front matter.
        self::assertSame(3, $frontMatter->range()->startOffset);
        self::assertSame(24, $frontMatter->range()->endOffset);
        self::assertSame("---\ntitle: Hello\n---\n", $frontMatter->text());
    }

    public function testHeadingImmediatelyAfterTheClosingFenceStaysAHeading(): void
    {
        $document = self::github("---\ntitle: Hello\n---\n# Heading\n");

        self::assertNotNull($document->frontMatter());
        self::assertSame("<h1>Heading</h1>\n", $document->toHtml());

        $title = $document->title();
        self::assertNotNull($title);
        self::assertSame('Heading', $title->text());
    }

    public function testBlockWithoutTrailingNewlineIsComplete(): void
    {
        $frontMatter = self::frontMatter("---\na: 1\n---");

        self::assertSame("---\na: 1\n---", $frontMatter->text());
        self::assertSame("a: 1\n", $frontMatter->content());
    }

    public function testDocumentMadeOnlyOfFrontMatterRendersNoHtml(): void
    {
        $source = "---\ntitle: Hello\n---\n";
        $document = self::github($source);

        self::assertNotNull($document->frontMatter());
        self::assertSame('', $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
    }

    public function testFenceRightAfterTheClosingFenceIsAThematicBreak(): void
    {
        $document = self::github("---\na: 1\n---\n---\n");

        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);
        self::assertSame("---\na: 1\n---\n", $frontMatter->text());
        self::assertSame("<hr />\n", $document->toHtml());
    }

    public function testUnterminatedBlockDoesNotSwallowALongDocument(): void
    {
        $document = self::github("---\n" . str_repeat("Paragraph text\n\n", 2000));

        self::assertNull($document->frontMatter());
        self::assertSame(2000, $document->query()->kind('paragraph')->get()->count());
    }

    public function testCommonMarkProfileStillSeesAThematicBreak(): void
    {
        $source = "---\ntitle: Hello\n---\n\nBody.\n";
        $document = Markdown::commonmark()->fromString($source);

        self::assertNull($document->frontMatter());
        self::assertSame("<hr />\n<h2>title: Hello</h2>\n<p>Body.</p>\n", $document->toHtml());
    }

    public function testGfmProfileStillSeesAThematicBreak(): void
    {
        $source = "---\ntitle: Hello\n---\n\nBody.\n";
        $document = Markdown::gfm()->fromString($source);

        self::assertNull($document->frontMatter());
        self::assertSame("<hr />\n<h2>title: Hello</h2>\n<p>Body.</p>\n", $document->toHtml());
    }

    public function testSetextUnderlineAtDocumentStartIsUnaffected(): void
    {
        // The opening fence must be the whole first line, so a document whose
        // first line is text keeps its setext heading in every profile.
        $document = self::github("Title\n---\n\nBody.\n");

        self::assertNull($document->frontMatter());
        self::assertSame("<h2>Title</h2>\n<p>Body.</p>\n", $document->toHtml());
    }

    public function testPositionalConstructDeclinesTheGeneralBlockLoop(): void
    {
        $parser = new FrontMatterParser(24);
        $state = self::state("text\n---\n---\n");

        self::assertSame('', $parser->triggerBytes());
        self::assertNull($parser->tryStart($state, 0, false));
        self::assertTrue($state->nextLine());
        self::assertFalse($parser->opensDocument($state));

        $ordinal = $state->tape->allocate(24, ParseTape::NONE, 0, 0);
        $parser->close($state, $ordinal);
        self::assertNull($state->tape->payload($ordinal));
    }

    private static function state(string $markdown): ParserState
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);

        return new ParserState($buffer, $scanner, new LineMap($buffer, $scanner), new ParseTape());
    }
}
