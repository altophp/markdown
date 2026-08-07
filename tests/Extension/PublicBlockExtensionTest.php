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
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Tests\Extension\Fixture\PublicCalloutExtension;
use Alto\Markdown\Tests\Extension\Fixture\PublicCalloutOutput;
use PHPUnit\Framework\TestCase;

final class PublicBlockExtensionTest extends TestCase
{
    public function testPublicExtensionRendersTheSameHtmlThroughDirectAndDocumentLanes(): void
    {
        $source = ":::bad\"onclick\nBody\n:::\n";
        $factory = Markdown::commonmark()->with(new PublicCalloutExtension());
        $valid = ":::note\n## Nested\n\nBody.\n:::\n";
        $expected = "<aside class=\"callout callout-note\">\n<h2>Nested</h2>\n<p>Body.</p>\n</aside>\n";

        self::assertSame($expected, $factory->toHtml($valid));
        self::assertSame($expected, $factory->fromString($valid)->toHtml());
        self::assertStringNotContainsString('<aside', $factory->toHtml($source));
    }

    public function testPublicOutputContextEscapesExtensionOwnedAttributes(): void
    {
        $output = new PublicCalloutOutput();
        $context = new HtmlBlockOutputContext(
            new BlockState(['label' => '"><script>&', 'fence' => 3]),
            new SourceRange(0, 0),
            '',
            HtmlPolicy::safe(),
        );

        self::assertSame(
            "<aside class=\"callout callout-&quot;&gt;&lt;script&gt;&amp;\">\n<p>Safe child</p>\n</aside>\n",
            $output->render($context, "<p>Safe child</p>\n"),
        );
        self::assertSame('&lt;unsafe&gt;&amp;', $context->escapeText('<unsafe>&'));
    }

    public function testPublicHtmlOutputContextAppliesPolicyAndLimitsSourceAccess(): void
    {
        $source = "before:::note\nafter";
        $context = new HtmlBlockOutputContext(
            new BlockState(),
            new SourceRange(6, 13),
            $source,
            HtmlPolicy::safe(),
        );

        self::assertSame(':::note', $context->source());
        self::assertSame('', $context->escapeUrl('javascript:alert(1)'));
        self::assertSame('https://example.com/?a=1&amp;b=2', $context->escapeUrl('https://example.com/?a=1&b=2'));
        self::assertFalse($context->allowsRawHtml());
        self::assertTrue(new HtmlBlockOutputContext(
            new BlockState(),
            new SourceRange(0, 0),
            '',
            HtmlPolicy::spec(),
        )->allowsRawHtml());
    }

    public function testStandaloneOutputContextCannotRenderNestedMarkdown(): void
    {
        $context = new HtmlBlockOutputContext(
            new BlockState(),
            new SourceRange(0, 0),
            '',
            HtmlPolicy::safe(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Nested Markdown rendering is unavailable');

        $context->renderMarkdown('Nested.', new ParseOptions());
    }

    public function testPublicExtensionPrintsNormalizedMarkdownExplicitly(): void
    {
        $source = "::::warning  \nBody\n:::::\n";
        $document = Markdown::commonmark()
            ->with(new PublicCalloutExtension())
            ->fromString($source);
        $normalized = $document->toMarkdown(new RenderOptions());

        self::assertSame($source, $document->toMarkdown());
        self::assertSame(
            "::::warning\nBody\n::::\n",
            $normalized,
        );
        self::assertCount(1, Markdown::commonmark()
            ->with(new PublicCalloutExtension())
            ->fromString($normalized)
            ->query()
            ->kind('example:callout')
            ->get()
            ->all());
    }

    public function testDeepCustomContainersUseTheIterativeRenderPaths(): void
    {
        $depth = 600;
        $source = str_repeat(":::note\n", $depth)."Body\n".str_repeat(":::\n", $depth);
        $factory = Markdown::commonmark()->with(new PublicCalloutExtension());
        $options = (new ParseOptions())->withUnboundedNestingDepth();
        $direct = $factory->toHtml($source, $options);
        $document = $factory->fromString($source, $options);
        $normalized = $document->toMarkdown(new RenderOptions());

        self::assertSame($depth, substr_count($direct, '<aside'));
        self::assertSame($direct, $document->toHtml());
        self::assertSame($depth, substr_count($normalized, ':::note'));
    }

    public function testPublicExtensionParsesNestedMarkdownWithOriginalByteOffsets(): void
    {
        $source = "Préface\n\n:::note\n## Nested\n\nBody.\n:::\n";
        $document = Markdown::commonmark()
            ->with(new PublicCalloutExtension())
            ->fromString($source);
        $model = $document->model();

        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        $tape = $model->htmlTape();
        $root = $model->htmlRootOrdinal();
        $preface = $tape->firstChildOrdinal($root);
        $callout = $tape->nextSiblingOrdinal($preface);
        $heading = $tape->firstChildOrdinal($callout);
        $paragraph = $tape->nextSiblingOrdinal($heading);
        $calloutStart = \strpos($source, ':::note');
        $calloutEnd = \strrpos($source, ':::');

        self::assertIsInt($calloutStart);
        self::assertIsInt($calloutEnd);

        self::assertSame('example:callout', $model->compiledProfile()->nodeKinds->get($tape->kindId($callout))->name);
        self::assertSame(BlockKind::ATX_HEADING, $tape->kindId($heading));
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame('note', $tape->extensionBlockState($callout)->string('label'));
        self::assertSame($calloutStart, $tape->startOffset($callout));
        self::assertSame($calloutEnd + 3, $tape->endOffset($callout));
        self::assertSame($source, $document->toMarkdown());
    }

    public function testFactoryReuseDoesNotLeakExtensionBlockState(): void
    {
        $factory = Markdown::commonmark()->with(new PublicCalloutExtension());
        $first = $factory->fromString(":::note\nFirst\n:::\n");
        $second = $factory->fromString(":::warning\nSecond\n:::\n");

        self::assertSame('note', $this->calloutState($first->model())->string('label'));
        self::assertSame('warning', $this->calloutState($second->model())->string('label'));
    }

    public function testUnlabelledAndIndentedFencesRemainCoreMarkdown(): void
    {
        $factory = Markdown::commonmark()->with(new PublicCalloutExtension());

        foreach ([":::\n", "    :::note\n"] as $index => $source) {
            $model = $factory->fromString($source)->model();

            self::assertInstanceOf(ParsedDocumentModel::class, $model);
            self::assertSame(
                0 === $index ? BlockKind::PARAGRAPH : BlockKind::INDENTED_CODE,
                $model->htmlTape()->kindId($model->htmlTape()->firstChildOrdinal($model->htmlRootOrdinal())),
            );
        }
    }

    public function testNestedCalloutsKeepIndependentState(): void
    {
        $source = "::::outer\n:::inner\nBody\n:::\n::::\n";
        $model = Markdown::commonmark()
            ->with(new PublicCalloutExtension())
            ->fromString($source)
            ->model();

        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        $tape = $model->htmlTape();
        $outer = $tape->firstChildOrdinal($model->htmlRootOrdinal());
        $inner = $tape->firstChildOrdinal($outer);
        $outerStart = \strpos($source, '::::outer');
        $outerEnd = \strrpos($source, ':::');

        self::assertIsInt($outerStart);
        self::assertIsInt($outerEnd);

        self::assertSame('outer', $tape->extensionBlockState($outer)->string('label'));
        self::assertSame('inner', $tape->extensionBlockState($inner)->string('label'));
        self::assertSame($outerStart, $tape->startOffset($outer));
        self::assertSame($outerEnd + 3, $tape->endOffset($outer));
    }

    public function testEmptyAndUnterminatedCalloutsHaveExactRanges(): void
    {
        $factory = Markdown::commonmark()->with(new PublicCalloutExtension());

        foreach ([":::empty\n:::\n", ":::open\nBody\n"] as $source) {
            $model = $factory->fromString($source)->model();

            self::assertInstanceOf(ParsedDocumentModel::class, $model);

            $tape = $model->htmlTape();
            $callout = $tape->firstChildOrdinal($model->htmlRootOrdinal());

            self::assertSame(0, $tape->startOffset($callout));
            self::assertSame(\strlen(rtrim($source, "\n")), $tape->endOffset($callout));
        }
    }

    public function testInvalidOpeningFencesRemainParagraphs(): void
    {
        $factory = Markdown::commonmark()->with(new PublicCalloutExtension());

        foreach (["::note\n", ":::123\n", ":::bad label\n"] as $source) {
            $model = $factory->fromString($source)->model();

            self::assertInstanceOf(ParsedDocumentModel::class, $model);
            self::assertSame(
                BlockKind::PARAGRAPH,
                $model->htmlTape()->kindId($model->htmlTape()->firstChildOrdinal($model->htmlRootOrdinal())),
            );
        }
    }

    private function calloutState(object $model): BlockState
    {
        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        $tape = $model->htmlTape();
        $callout = $tape->firstChildOrdinal($model->htmlRootOrdinal());

        self::assertNotSame(ParseTape::NONE, $callout);

        return $tape->extensionBlockState($callout);
    }
}
