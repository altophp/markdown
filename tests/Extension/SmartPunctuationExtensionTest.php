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

namespace Alto\Markdown\Tests\Extension;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\SmartPunctuation\SmartPunctuationExtension;
use Alto\Markdown\Extension\SmartPunctuation\SmartPunctuationPolicy;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmartPunctuationExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testSmartPunctuationRendersThroughEveryLaneAndPreservesMarkdown(): void
    {
        $factory = Markdown::github()->with(new SmartPunctuationExtension());
        $source = "\"Hello,\" she said... It's ready -- really --- now.\n";
        $expected = '<p>'
            . "\u{201C}Hello,\u{201D} she said\u{2026} It\u{2019}s ready "
            . "\u{2013} really \u{2014} now.</p>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame(
            "\u{201C}Hello\u{201D} \u{2013} \u{2026} \u{2026}",
            $factory->toInlineHtml('"Hello" -- ... . . .'),
        );

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame(
            "\"Hello\\,\" she said... It's ready -- really --- now\\.\n",
            $document->toMarkdown(new RenderOptions()),
        );
    }

    public function testSmartPunctuationWorksInsideRichInlinesAndTables(): void
    {
        $factory = Markdown::github()->with(new SmartPunctuationExtension());
        $source = "| Message |\n| --- |\n| \"Use **Alto**\" -- now... |\n";
        $html = $factory->toHtml($source);

        self::assertStringContainsString(
            "\u{201C}Use <strong>Alto</strong>\u{201D} \u{2013} now\u{2026}",
            $html,
        );
        self::assertSame($source, $factory->fromString($source)->toMarkdown());
    }

    public function testSemanticHeadingTextUsesTheTypographicCharacters(): void
    {
        $factory = Markdown::commonmark()->with(new SmartPunctuationExtension());
        $document = $factory->fromString("# \"Café\" -- guide...\n");

        self::assertSame(
            "\u{201C}Café\u{201D} \u{2013} guide\u{2026}",
            $document->title()?->text(),
        );
    }

    public function testCodeRawHtmlAndEscapedPunctuationStayLiteral(): void
    {
        $factory = Markdown::commonmark()->with(new SmartPunctuationExtension());

        self::assertSame(
            '<code>&quot;-- ...</code> &lt;i title=&quot;-- ...&quot;&gt; &quot;-- ...',
            $factory->toInlineHtml('`"-- ...` <i title="-- ..."> \\"\\-- \\...'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dashRuns(): iterable
    {
        yield 'one stays literal' => ['-', '-'];
        yield 'two become one en dash' => ['--', "\u{2013}"];
        yield 'three become one em dash' => ['---', "\u{2014}"];
        yield 'four become two en dashes' => ['----', "\u{2013}\u{2013}"];
        yield 'five become em then en' => ['-----', "\u{2014}\u{2013}"];
        yield 'six become two em dashes' => ['------', "\u{2014}\u{2014}"];
        yield 'seven become em then two en dashes' => ['-------', "\u{2014}\u{2013}\u{2013}"];
    }

    #[DataProvider('dashRuns')]
    public function testCompleteDashRunsUseStableReplacementRules(string $source, string $expected): void
    {
        $factory = Markdown::commonmark()->with(new SmartPunctuationExtension());

        self::assertSame($expected, $factory->toInlineHtml($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function quoteCases(): iterable
    {
        yield 'double pair' => ['"word"', "\u{201C}word\u{201D}"];
        yield 'single pair' => ["'word'", "\u{2018}word\u{2019}"];
        yield 'apostrophe' => ["don't", "don\u{2019}t"];
        yield 'nested pairs' => ['"a \'word\' here"', "\u{201C}a \u{2018}word\u{2019} here\u{201D}"];
        yield 'unpaired double' => ['"', "\u{201C}"];
        yield 'unpaired single' => ["'", "\u{2019}"];
        yield 'two-byte letter after opener' => ["\"\u{00E9}\"", "\u{201C}\u{00E9}\u{201D}"];
        yield 'three-byte punctuation after opener' => ["\"\u{2026}", "\u{201C}\u{2026}"];
        yield 'four-byte symbol after opener' => ["\"\u{1F642}", "\u{201C}\u{1F642}"];
        yield 'unicode space before opener' => ["\u{00A0}\"word", "\u{00A0}\u{201C}word"];
        yield 'unicode punctuation before closer' => ["\u{2026}\"", "\u{2026}\u{201D}"];
    }

    #[DataProvider('quoteCases')]
    public function testQuotesUseContextualOpenersAndClosers(string $source, string $expected): void
    {
        $factory = Markdown::commonmark()->with(new SmartPunctuationExtension());

        self::assertSame($expected, $factory->toInlineHtml($source));
    }

    public function testQuoteMarksAreConfigurableAndEscapedAsText(): void
    {
        $factory = Markdown::commonmark()->with(new SmartPunctuationExtension(
            new SmartPunctuationPolicy(
                doubleQuoteOpener: '<open>',
                doubleQuoteCloser: '<close>',
                singleQuoteOpener: '[',
                singleQuoteCloser: ']',
            ),
        ));

        self::assertSame(
            '&lt;open&gt;hello&lt;close&gt; [world]',
            $factory->toInlineHtml('"hello" \'world\''),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQuoteMarks(): iterable
    {
        yield 'empty' => [''];
        yield 'invalid UTF-8' => ["\xFF"];
    }

    #[DataProvider('invalidQuoteMarks')]
    public function testInvalidQuoteMarksFailEarly(string $replacement): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('non-empty valid UTF-8');

        new SmartPunctuationPolicy(singleQuoteCloser: $replacement);
    }

    public function testInactiveProfilesPayNoSmartPunctuationCost(): void
    {
        Instrumentation::reset();
        self::assertSame(
            "<p>&quot;Plain&quot; -- text...</p>\n",
            Markdown::commonmark()->toHtml("\"Plain\" -- text...\n"),
        );
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);

        $factory = Markdown::commonmark()->with(new SmartPunctuationExtension());
        Instrumentation::reset();
        self::assertSame("<p>Plain text</p>\n", $factory->toHtml("Plain text\n"));
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);
    }
}
