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
use Alto\Markdown\Extension\PairedDelimiter\PairedDelimiterExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PairedDelimiterExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testConfigurablePairRendersThroughEveryLaneAndPreservesSource(): void
    {
        $factory = Markdown::github()->with(new PairedDelimiterExtension(
            name: 'inserted',
            opening: '++',
            closing: '++',
            element: 'ins',
        ));
        $source = "Keep ++this & **literal**++ text.\n";
        $expected = "<p>Keep <ins>this &amp; **literal**</ins> text.</p>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame('Keep <ins>this</ins>', $factory->toInlineHtml('Keep ++this++'));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame(
            "Keep ++this & **literal**++ text\\.\n",
            $document->toMarkdown(new RenderOptions()),
        );
    }

    public function testDistinctDelimitersWorkInTablesAndCuratedHtml(): void
    {
        $factory = Markdown::github()->with(new PairedDelimiterExtension(
            name: 'key',
            opening: '{{',
            closing: '}}',
            element: 'kbd',
        ));
        $source = "| Key |\n| --- |\n| {{Ctrl+C}} |\n";
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::curated());

        self::assertStringContainsString('<kbd>Ctrl+C</kbd>', $factory->toHtml($source));
        self::assertStringContainsString('<kbd>Ctrl+C</kbd>', $factory->toHtml($source, renderOptions: $options));
        self::assertSame($source, $factory->fromString($source)->toMarkdown());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literalCases(): iterable
    {
        yield 'empty' => ['++++', '++++'];
        yield 'unclosed' => ['++open', '++open'];
        yield 'multiline' => ["++one\ntwo++", "++one\ntwo++"];
        yield 'wrong opener' => ['+one++', '+one++'];
        yield 'escaped opener' => ['\\++text++', '++text++'];
    }

    #[DataProvider('literalCases')]
    public function testMalformedPairsStayLiteral(string $source, string $expected): void
    {
        $factory = Markdown::commonmark()->with(new PairedDelimiterExtension(
            name: 'inserted',
            opening: '++',
            closing: '++',
            element: 'ins',
        ));

        self::assertSame($expected, $factory->toInlineHtml($source));
    }

    public function testProfilesWithoutTheExtensionPayNoInlineDispatchCost(): void
    {
        Instrumentation::reset();
        self::assertSame("<p>Plain text.</p>\n", Markdown::commonmark()->toHtml("Plain text.\n"));
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);

        $factory = Markdown::commonmark()->with(new PairedDelimiterExtension(
            name: 'inserted',
            opening: '++',
            closing: '++',
            element: 'ins',
        ));
        Instrumentation::reset();
        self::assertSame("<p>Plain text.</p>\n", $factory->toHtml("Plain text.\n"));
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'name' => ['Not Valid', '++', '++', 'ins'];
        yield 'empty opener' => ['pair', '', '++', 'ins'];
        yield 'long closer' => ['pair', '++', '12345678901234567', 'ins'];
        yield 'whitespace' => ['pair', '+ +', '++', 'ins'];
        yield 'control byte' => ['pair', "+\x01+", '++', 'ins'];
        yield 'unsafe element' => ['pair', '++', '++', 'script'];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationsFailEarly(
        string $name,
        string $opening,
        string $closing,
        string $element,
    ): void {
        $this->expectException(InvalidMarkdownArgumentException::class);

        new PairedDelimiterExtension($name, $opening, $closing, $element);
    }

    public function testParserOwnedTriggerIsRejectedAtCompilation(): void
    {
        $extension = new PairedDelimiterExtension(
            name: 'stronger',
            opening: '**',
            closing: '**',
            element: 'strong',
        );

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('reserved by the active Markdown profile');

        Markdown::commonmark()->with($extension);
    }
}
