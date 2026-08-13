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

use Alto\Markdown\Markdown;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use PHPUnit\Framework\TestCase;

final class HtmlContainerRenderingBoundaryTest extends TestCase
{
    private const string SOURCE = <<<'MD'
        # Start

        > Quote.
        >
        > - tight **one**
        >   - nested
        >
        > - loose one
        >
        >   loose two

        > [!WARNING]
        > Alert **body**.

        # End

        Done.
        MD;

    private const string BLOCK_QUOTE_HTML = <<<'HTML'
        <blockquote>
        <p>Quote.</p>
        <ul>
        <li>
        <p>tight <strong>one</strong></p>
        <ul>
        <li>nested</li>
        </ul>
        </li>
        <li>
        <p>loose one</p>
        <p>loose two</p>
        </li>
        </ul>
        </blockquote>
        HTML;

    private const string SECTION_HTML = <<<'HTML'
        <h1>Start</h1>
        <blockquote>
        <p>Quote.</p>
        <ul>
        <li>
        <p>tight <strong>one</strong></p>
        <ul>
        <li>nested</li>
        </ul>
        </li>
        <li>
        <p>loose one</p>
        <p>loose two</p>
        </li>
        </ul>
        </blockquote>
        <div class="markdown-alert markdown-alert-warning">
        <p class="markdown-alert-title">Warning</p>
        <p>Alert <strong>body</strong>.</p>
        </div>
        HTML;

    public function testDocumentSectionAndNodeShareTheContainerContract(): void
    {
        $factory = Markdown::github();
        $document = $factory->fromString(self::SOURCE);
        $renderer = new HtmlDocumentRenderer();
        $blockQuote = $document->query()
            ->kind('block-quote')
            ->get()
            ->first();

        self::assertSame(self::SECTION_HTML . "\n<h1>End</h1>\n<p>Done.</p>\n", $document->toHtml());
        self::assertSame($document->toHtml(), $factory->toHtml(self::SOURCE));
        self::assertSame(self::SECTION_HTML . "\n", $renderer->renderSection($document->model(), $document->section('Start')));
        self::assertInstanceOf(NodeHandle::class, $blockQuote);
        self::assertSame(self::BLOCK_QUOTE_HTML . "\n", $renderer->renderNode($document->model(), $blockQuote));
    }

    public function testNodeRenderingMaterializesOnlyTheSelectedNode(): void
    {
        $document = Markdown::gfm()->fromString("First *one*.\n\nSecond **two**.\n");
        $paragraph = $document->query()
            ->kind('paragraph')
            ->get()
            ->first();
        self::assertInstanceOf(NodeHandle::class, $paragraph);

        Instrumentation::reset();
        $renderer = new HtmlDocumentRenderer();

        self::assertSame("<p>First <em>one</em>.</p>\n", $renderer->renderNode($document->model(), $paragraph));
        self::assertSame(1, Instrumentation::$inlineParses);
        self::assertSame(0, Instrumentation::$cacheHits);

        self::assertSame("<p>First <em>one</em>.</p>\n", $renderer->renderNode($document->model(), $paragraph));
        self::assertSame(1, Instrumentation::$inlineParses);
        self::assertSame(1, Instrumentation::$cacheHits);
    }

    public function testSectionRenderingDoesNotMaterializeAnotherSection(): void
    {
        $document = Markdown::gfm()->fromString("# One\n\nFirst *one*.\n\n# Two\n\nSecond **two**.\n");
        $section = $document->section('One');

        Instrumentation::reset();

        self::assertSame(
            "<h1>One</h1>\n<p>First <em>one</em>.</p>\n",
            new HtmlDocumentRenderer()->renderSection($document->model(), $section),
        );
        self::assertSame(1, Instrumentation::$inlineParses);
        self::assertSame(0, Instrumentation::$cacheHits);
    }
}
