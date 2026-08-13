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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\HtmlRendererEngine;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

final class HtmlRendererEngineBoundaryTest extends TestCase
{
    private const int FRAME_DEPTH = 501;

    public function testSectionOutsideTheRequestedRangeIsEmpty(): void
    {
        $model = Markdown::gfm()->fromString("# One\n\nText.\n")->model();
        self::assertInstanceOf(ParsedDocumentModel::class, $model);
        $engine = new HtmlRendererEngine();
        $first = $model->htmlTape()->firstChildOrdinal($model->htmlRootOrdinal());

        self::assertSame('', $engine->renderSection($model, ParseTape::NONE, PHP_INT_MAX, HtmlPolicy::safe()));
        self::assertSame('', $engine->renderSection($model, $first, $model->htmlTape()->startOffset($first), HtmlPolicy::safe()));
    }

    public function testFrameStackRendersTightTaskItem(): void
    {
        $html = $this->renderDeep("- [x] task\n");

        self::assertStringContainsString(
            "<ul>\n<li><input checked=\"\" disabled=\"\" type=\"checkbox\"> task</li>\n</ul>\n",
            $html,
        );
    }

    public function testFrameStackRendersOrderedListWithNestedContainer(): void
    {
        $quote = self::quotePrefix();
        $markdown = $quote . "3. outer\n" . $quote . "   - inner\n";
        $html = Markdown::gfm()->fromString($markdown, self::deepOptions())->toHtml();

        self::assertStringContainsString(
            "<ol start=\"3\">\n<li>outer\n<ul>\n<li>inner</li>\n</ul>\n</li>\n</ol>\n",
            $html,
        );
    }

    public function testFrameStackRendersEmptyListItem(): void
    {
        $expected = "<ul>\n<li></li>\n</ul>\n";

        self::assertSame($expected, Markdown::gfm()->fromString("-\n")->toHtml());
        self::assertStringContainsString($expected, $this->renderDeep("-\n"));
    }

    public function testFrameStackDeliversLeafBlocksIntoAListItem(): void
    {
        $quote = self::quotePrefix();
        $markdown = $quote . "- item\n"
            . $quote . "\n"
            . $quote . "  ```\n"
            . $quote . "  code\n"
            . $quote . "  ```\n";
        $html = Markdown::gfm()->fromString($markdown, self::deepOptions())->toHtml();

        self::assertStringContainsString(
            "<ul>\n<li>\n<p>item</p>\n<pre><code>code\n</code></pre>\n</li>\n</ul>\n",
            $html,
        );
    }

    public function testFrameStackRendersGitHubAlert(): void
    {
        $quote = self::quotePrefix();
        $markdown = $quote . "> [!WARNING]\n" . $quote . "> body\n";
        $html = Markdown::github()->fromString($markdown, self::deepOptions())->toHtml();

        self::assertStringContainsString(
            "<div class=\"markdown-alert markdown-alert-warning\">\n"
            . "<p class=\"markdown-alert-title\">Warning</p>\n"
            . "<p>body</p>\n"
            . "</div>\n",
            $html,
        );
    }

    public function testWorkspaceRendererBalancesOpaqueAndTableTimingStages(): void
    {
        $source = <<<'MD'
            ```php
            code
            ```

            | A |
            | --- |
            | B |

            <div>
            raw
            </div>
            MD;
        $document = Markdown::gfm()->fromString($source);

        Instrumentation::measure();

        try {
            $document->toHtml(new RenderOptions(htmlPolicy: HtmlPolicy::spec()));
        } finally {
            Instrumentation::disable();
        }

        self::assertSame(1, Instrumentation::$stageEnters['render-output'] ?? 0);
        self::assertGreaterThanOrEqual(3, Instrumentation::$stageEnters['opaque-read'] ?? 0);
        self::assertGreaterThanOrEqual(2, Instrumentation::$stageEnters['plain-scan'] ?? 0);
        self::assertSame(0, Instrumentation::regionDepth());
    }

    private function renderDeep(string $leaf): string
    {
        return Markdown::gfm()
            ->fromString(self::quotePrefix() . $leaf, self::deepOptions())
            ->toHtml();
    }

    private static function quotePrefix(): string
    {
        return str_repeat('> ', self::FRAME_DEPTH);
    }

    private static function deepOptions(): ParseOptions
    {
        return (new ParseOptions())->withMaxNestingDepth(2048);
    }
}
