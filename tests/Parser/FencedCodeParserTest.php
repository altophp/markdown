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
use Alto\Markdown\Parser\Block\FencedCodeParser;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Tests\Conformance\TestHtmlRenderer;
use PHPUnit\Framework\TestCase;

final class FencedCodeParserTest extends TestCase
{
    /**
     * @return array{ParseTape, int}
     */
    private static function parse(string $markdown): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $map, $tape);

        $document = new BlockParser()->parse($state);

        return [$tape, $document];
    }

    private static function render(string $markdown): string
    {
        return new TestHtmlRenderer()->render($markdown);
    }

    public function testBacktickFenceRendersContentVerbatim(): void
    {
        self::assertSame(
            "<pre><code>&lt;\n &gt;\n</code></pre>\n",
            self::render("```\n<\n >\n```\n"),
        );
    }

    public function testTildeFence(): void
    {
        self::assertSame(
            "<pre><code>&lt;\n &gt;\n</code></pre>\n",
            self::render("~~~\n<\n >\n~~~\n"),
        );
    }

    public function testInfoStringBecomesLanguageClassAndFlagsHoldIndent(): void
    {
        [$tape, $document] = self::parse("```ruby\ndef foo\nend\n```\n");

        $code = $tape->firstChildOrdinal($document);

        self::assertSame(BlockKind::FENCED_CODE, $tape->kindId($code));
        self::assertSame(0, $tape->flags($code));
        self::assertStringStartsWith('@', (string) $tape->payload($code));
        self::assertStringEndsWith('|ruby', (string) $tape->payload($code));
        self::assertSame(
            "<pre><code class=\"language-ruby\">def foo\nend\n</code></pre>\n",
            self::render("```ruby\ndef foo\nend\n```\n"),
        );
    }

    public function testClosingFenceMustMatchCharAndBeLongEnough(): void
    {
        self::assertSame(
            "<pre><code>aaa\n~~~\n</code></pre>\n",
            self::render("```\naaa\n~~~\n```\n"),
        );

        self::assertSame(
            "<pre><code>aaa\n```\n</code></pre>\n",
            self::render("````\naaa\n```\n``````\n"),
        );
    }

    public function testUnclosedFenceRunsToEndOfDocument(): void
    {
        self::assertSame(
            "<pre><code>aaa\n</code></pre>\n",
            self::render("```\naaa\n"),
        );
    }

    public function testCompactRootFenceNormalizesCrLfAndFinalLineEnding(): void
    {
        self::assertSame(
            "<pre><code>one\ntwo\n</code></pre>\n",
            self::render("```\r\none\r\ntwo"),
        );
    }

    public function testEmptyFenceRendersEmptyCode(): void
    {
        self::assertSame("<pre><code></code></pre>\n", self::render("```\n```\n"));
        self::assertSame("<pre><code></code></pre>\n", self::render("```\n"));
    }

    public function testAllBlankContentIsPreserved(): void
    {
        self::assertSame(
            "<pre><code>\n  \n</code></pre>\n",
            self::render("```\n\n  \n```\n"),
        );
    }

    public function testOpeningIndentStripsEquivalentContentIndent(): void
    {
        [$tape, $document] = self::parse("   ```\n   aaa\n    aaa\n  aaa\n   ```\n");

        $code = $tape->firstChildOrdinal($document);
        self::assertSame(3, $tape->flags($code));

        self::assertSame(
            "<pre><code>aaa\n aaa\naaa\n</code></pre>\n",
            self::render("   ```\n   aaa\n    aaa\n  aaa\n   ```\n"),
        );
    }

    public function testFourSpacesIsIndentedCodeNotFence(): void
    {
        [$tape, $document] = self::parse("    ```\n    aaa\n    ```\n");

        self::assertSame(BlockKind::INDENTED_CODE, $tape->kindId($tape->firstChildOrdinal($document)));
        self::assertSame(
            "<pre><code>```\naaa\n```\n</code></pre>\n",
            self::render("    ```\n    aaa\n    ```\n"),
        );
    }

    public function testBacktickInfoWithBacktickIsNotAFence(): void
    {
        [$tape, $document] = self::parse("``` aa ```\nfoo\n");

        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($tape->firstChildOrdinal($document)));
    }

    public function testInteriorFenceIndentedFourSpacesIsNotClosing(): void
    {
        self::assertSame(
            "<pre><code>aaa\n    ```\n</code></pre>\n",
            self::render("```\naaa\n    ```\n"),
        );
    }

    /**
     * The contiguous-span payload records where the content stops. That end is
     * derived at close time from the block's end offset rather than tracked on
     * every content line (PD.1), and the missing final newline is derived from
     * the byte before it, so both cases are pinned here.
     */
    public function testContiguousSpanPayloadEndsAtTheLastContentLine(): void
    {
        [$tape, $document] = self::parse("```php\naaa\nbbb\n```\ntail\n");
        $code = $tape->firstChildOrdinal($document);

        self::assertSame('@7:15:0!|php', $tape->payload($code));

        [$tape, $document] = self::parse("```php\naaa\nbbb");
        $code = $tape->firstChildOrdinal($document);

        self::assertSame('@7:14:1|php', $tape->payload($code));
    }

    public function testUnclosedFenceWithoutFinalNewlineStillEndsItsCode(): void
    {
        self::assertSame(
            "<pre><code class=\"language-php\">aaa\nbbb\n</code></pre>\n",
            self::render("```php\naaa\nbbb"),
        );
        self::assertSame(
            "<pre><code class=\"language-php\">aaa\nbbb\n</code></pre>\n",
            self::render("```php\naaa\nbbb\n"),
        );
    }

    public function testFenceConstructRejectsBlankAndNonFenceLinesDirectly(): void
    {
        $parser = new FencedCodeParser();

        self::assertNull($parser->tryStart(self::state("   \n"), 0, false));
        self::assertNull($parser->tryStart(self::state("plain text\n"), 0, false));
    }

    private static function state(string $markdown): ParserState
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);

        return new ParserState($buffer, $scanner, $map, new ParseTape());
    }
}
