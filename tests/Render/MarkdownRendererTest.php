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

namespace Alto\Markdown\Tests\Render;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\RenderException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Operation\EditJournal;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use Alto\Markdown\Render\MarkdownRenderer;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Source\SourceDocument;
use Alto\Markdown\Tests\Support\MarkdownRoundTripHarness;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function testDocumentToMarkdownPreservesOriginalSource(): void
    {
        $document = Markdown::github()->fromString("# Title ###\r\n\r\nBody\r\n");

        self::assertSame("# Title ###\r\n\r\nBody\r\n", $document->toMarkdown());
    }

    public function testRenderOptionsRequestNormalizedRendering(): void
    {
        $document = Markdown::github()->fromString("# Title ###\r\n\r\nBody\r\n");

        self::assertSame("# Title\n\nBody\n", $document->toMarkdown(new RenderOptions()));
    }

    public function testFirstBlockPrinterCorpusRoundTrips(): void
    {
        $comparison = new MarkdownRoundTripHarness(Markdown::github())->compareCorpus([
            'empty-document' => '',
            'paragraph-plain' => "Alpha beta.\n",
            'paragraph-inline-source' => "Paragraph with [link](https://example.com) and `code`.\n",
            'heading-levels' => "# One\n\n### Three\n\n###### Six\n",
            'heading-empty' => "#\n",
            'heading-closing-hashes' => "# Title ###\n",
            'thematic-break-stars' => "***\n",
            'thematic-break-spaced' => "- - -\n",
            'fenced-empty' => "```\n```\n",
            'fenced-language' => "```php\necho \"ok\";\n```\n",
            'indented-simple' => "    echo \"ok\";\n",
            'mixed-leaves' => "# Title\n\nAlpha.\n\n---\n\n```php\necho \"ok\";\n```\n\nOmega.\n",
            'blockquote-simple' => "> quote\n",
            'blockquote-multiple-blocks' => "> # Title\n>\n> Body\n",
            'unordered-list' => "- one\n- two\n",
            'ordered-list-start' => "3. three\n4. four\n",
            'nested-list' => "- parent\n  - child\n",
            'tight-list-with-continuation' => "- one\n  continued\n- two\n",
            'loose-list' => "- one\n\n- two\n",
            'blockquote-list' => "> - one\n> - two\n",
        ]);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testHtmlBlocksRoundTrip(): void
    {
        $comparison = new MarkdownRoundTripHarness(Markdown::github())->compareCorpus([
            'html-block-simple' => "<div class=\"x\">\nraw <em>markup</em>\n</div>\n",
            'html-details' => "<details>\n<summary>More</summary>\n\nInside.\n\n</details>\n",
        ]);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testCodeFenceGrowsPastBacktickRunsInCode(): void
    {
        $source = "`````\n```\n````\n`````\n";
        $rendered = Markdown::github()->fromString($source)->toMarkdown(new RenderOptions());

        self::assertStringStartsWith("`````\n", $rendered);
        self::assertSame($source, Markdown::github()->fromString($rendered)->toMarkdown());
    }

    public function testEmptyContainersNormalizeWithoutSyntheticContent(): void
    {
        self::assertSame(
            ">\n",
            Markdown::github()->fromString(">\n")->toMarkdown(new RenderOptions()),
        );
        self::assertSame(
            "-\n",
            Markdown::github()->fromString("-\n")->toMarkdown(new RenderOptions()),
        );
    }

    public function testStyleCanSwitchFenceMarkerAndFinalNewline(): void
    {
        $document = Markdown::github()->fromString("```\necho \"ok\";\n```\n");
        $options = new RenderOptions(style: new MarkdownStyle(fenceMarker: '~', finalNewline: false));

        self::assertSame("~~~\necho \"ok\";\n~~~", $document->toMarkdown($options));
    }

    public function testSetextHeadingNormalizesToAtxSyntax(): void
    {
        $document = Markdown::github()->fromString("Title\n=====\n");

        self::assertSame("# Title\n", $document->toMarkdown(new RenderOptions()));
    }

    public function testRendererRejectsAnotherDocumentModelImplementation(): void
    {
        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('MarkdownRenderer requires a parsed document model.');

        new MarkdownRenderer()->render(self::foreignModel());
    }

    public function testHtmlRendererRendersFragmentsAndRejectsAnotherModel(): void
    {
        $fragment = Markdown::commonmark()->fragment()->paragraph('Hello')->toFragment();
        $renderer = new HtmlDocumentRenderer();

        self::assertSame("<p>Hello</p>\n", $renderer->renderFragment($fragment));

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('HtmlDocumentRenderer requires a parsed document model.');

        $renderer->renderDocument(self::foreignModel());
    }

    private static function foreignModel(): DocumentModel
    {
        return new class implements DocumentModel {
            public function generation(): int
            {
                throw new \LogicException('Not used.');
            }

            public function root(): NodeHandle
            {
                throw new \LogicException('Not used.');
            }

            public function node(NodeId $id): NodeHandle
            {
                throw new \LogicException('Not used.');
            }

            public function source(): SourceDocument
            {
                throw new \LogicException('Not used.');
            }

            public function journal(): EditJournal
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
