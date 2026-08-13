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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Extension\Highlight\HighlightExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class HighlightExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testHighlightRendersThroughEveryLaneAndPreservesMarkdown(): void
    {
        $factory = Markdown::github()->with(new HighlightExtension());
        $source = "A ==small <mark>== in a table.\n\n| Value |\n| --- |\n| ==large== |\n";
        $expected = "<p>A <mark>small &lt;mark&gt;</mark> in a table.</p>\n"
            . "<table>\n<thead>\n<tr>\n<th>Value</th>\n</tr>\n</thead>\n"
            . "<tbody>\n<tr>\n<td><mark>large</mark></td>\n</tr>\n</tbody>\n</table>\n";

        self::assertSame($expected, $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame(
            "A ==small <mark>== in a table\\.\n\n| Value |\n| --- |\n| ==large== |\n",
            $document->toMarkdown(new RenderOptions()),
        );
        self::assertSame('<mark>small &lt;mark&gt;</mark>', $factory->toInlineHtml('==small <mark>=='));
    }

    public function testHighlightIsAPlainTextLeaf(): void
    {
        $factory = Markdown::commonmark()->with(new HighlightExtension());

        self::assertSame(
            "<p><mark>**important**</mark> and <mark>[label](/target)</mark></p>\n",
            $factory->toHtml("==**important**== and ==[label](/target)==\n"),
        );
        self::assertSame(
            "<p><img src=\"/portrait\" alt=\"Ada\" /></p>\n",
            $factory->toHtml("![==Ada==](/portrait)\n"),
        );
    }

    public function testHighlightRequiresAClosedSingleLineNonWhitespaceSpan(): void
    {
        $factory = Markdown::commonmark()->with(new HighlightExtension());

        $examples = [
            '== ==' => '== ==',
            '==open' => '==open',
            '== left ==' => '== left ==',
            '===triple===' => '===triple===',
            'a == b == c ==' => 'a == b == c ==',
            "==across\nlines==" => "==across\nlines==",
            '==keeps == an exact close==' => '<mark>keeps == an exact close</mark>',
        ];

        foreach ($examples as $source => $expected) {
            self::assertSame("<p>{$expected}</p>\n", $factory->toHtml($source . "\n"));
        }

        self::assertSame("==line\n==", $factory->toInlineHtml("==line\n=="));
    }

    public function testEscapedAndCodeSpanSyntaxStaysLiteral(): void
    {
        $factory = Markdown::commonmark()->with(new HighlightExtension());

        self::assertSame(
            "<p>==escaped== and <code>==code==</code></p>\n",
            $factory->toHtml("\\==escaped== and `==code==`\n"),
        );
    }

    public function testHighlightKeepsItsQualifiedKindAndOriginalRange(): void
    {
        $document = Markdown::commonmark()
            ->with(new HighlightExtension())
            ->fromString("# A ==marked== value\r\n");
        $heading = $document->headings()->first();
        $model = $document->model();

        self::assertNotNull($heading);
        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        $marks = array_values(array_filter(
            [...$model->traversalInlineEvents($heading->id()->ordinal)],
            static fn(array $event): bool => 'highlight:mark' === $event[1],
        ));

        self::assertCount(1, $marks);
        self::assertEquals(new SourceRange(4, 14), $marks[0][2]);
    }

    public function testInactiveAndTriggerFreeProfilesPayNoExtensionCost(): void
    {
        $plain = "No special syntax here.\n";

        Instrumentation::reset();
        self::assertSame("<p>No special syntax here.</p>\n", Markdown::commonmark()->toHtml($plain));
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);

        Instrumentation::reset();
        $factory = Markdown::commonmark()->with(new HighlightExtension());
        self::assertSame("<p>No special syntax here.</p>\n", $factory->toHtml($plain));
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);

        Instrumentation::reset();
        self::assertSame("<p>Equality: a = b.</p>\n", $factory->toHtml("Equality: a = b.\n"));
        self::assertSame(1, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);
    }
}
