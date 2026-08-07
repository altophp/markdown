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
use Alto\Markdown\Render\HtmlDocumentRenderer;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

/**
 * Warm rich-table rendering must reuse per-cell inline state across renders
 * (PL.3 / RP.11): the workspace lane re-parsed and re-rendered every rich
 * cell on each render because table cells are not blocks and fall outside the
 * block-keyed InlineCache.
 */
final class WarmTableCellCacheTest extends TestCase
{
    public function testWarmRenderReusesRichCellHtmlWithoutReparsing(): void
    {
        $renderer = new HtmlDocumentRenderer();
        $model = $this->model("| H1 | H2 |\n| --- | --- |\n| a `x` | b *y* |\n");

        Instrumentation::reset();
        $first = $renderer->renderDocument($model);

        self::assertSame(2, Instrumentation::$inlineParses);
        self::assertSame(2, Instrumentation::$tableCellHtmlMisses);
        self::assertSame(0, Instrumentation::$tableCellHtmlHits);

        Instrumentation::reset();
        $second = $renderer->renderDocument($model);

        self::assertSame($first, $second, 'warm render must be byte-identical');
        self::assertSame(0, Instrumentation::$inlineParses, 'warm render must not re-parse cells');
        self::assertSame(2, Instrumentation::$tableCellHtmlHits);
        self::assertSame(0, Instrumentation::$tableCellHtmlMisses);
        self::assertStringContainsString('<td>a <code>x</code></td>', $first);
        self::assertStringContainsString('<td>b <em>y</em></td>', $first);
    }

    public function testIdenticalCellsShareOneCacheEntry(): void
    {
        $renderer = new HtmlDocumentRenderer();
        // Both body rows carry the identical rich cell text "a `x`".
        $model = $this->model("| H |\n| --- |\n| a `x` |\n| a `x` |\n");

        Instrumentation::reset();
        $renderer->renderDocument($model);

        // Two cells, one distinct string: first misses and renders, second hits.
        self::assertSame(1, Instrumentation::$inlineParses);
        self::assertSame(1, Instrumentation::$tableCellHtmlMisses);
        self::assertSame(1, Instrumentation::$tableCellHtmlHits);
    }

    public function testPlainCellsStayOnFastPathAndNeverEnterTheCellCache(): void
    {
        $renderer = new HtmlDocumentRenderer();
        $model = $this->model("| H1 | H2 |\n| --- | --- |\n| plain one | plain two |\n");

        Instrumentation::reset();
        $renderer->renderDocument($model);
        $renderer->renderDocument($model);

        self::assertSame(0, Instrumentation::$inlineParses);
        self::assertSame(0, Instrumentation::$tableCellHtmlMisses);
        self::assertSame(0, Instrumentation::$tableCellHtmlHits);
    }

    public function testEditingACellThenReRenderingDropsStaleCellHtml(): void
    {
        $renderer = new HtmlDocumentRenderer();
        $model = $this->model("| H |\n| --- |\n| a `x` |\n");

        $before = $renderer->renderDocument($model);
        $renderer->renderDocument($model);
        self::assertStringContainsString('<code>x</code>', $before);

        // Rebase is a full re-parse: it changes the cell text and, like the
        // block caches, drops the stale per-cell HTML so nothing leaks across.
        $model->rebase("| H |\n| --- |\n| c `z` |\n");

        Instrumentation::reset();
        $after = $renderer->renderDocument($model);

        self::assertStringContainsString('<code>z</code>', $after);
        self::assertStringNotContainsString('<code>x</code>', $after);
        self::assertSame(1, Instrumentation::$inlineParses, 'the changed cell must render fresh');
        self::assertSame(1, Instrumentation::$tableCellHtmlMisses);

        // The rebuilt cache warms again on the next render.
        Instrumentation::reset();
        $again = $renderer->renderDocument($model);
        self::assertSame($after, $again);
        self::assertSame(0, Instrumentation::$inlineParses);
        self::assertSame(1, Instrumentation::$tableCellHtmlHits);
    }

    public function testTablePartsDecodeIsRebuiltAfterAGenerationBump(): void
    {
        $renderer = new HtmlDocumentRenderer();
        $model = $this->model("| H |\n| --- |\n| a `x` |\n");

        $first = $renderer->renderDocument($model);
        $ordinal = $model->firstChildOrdinal($model->rootNodeId()->ordinal);

        // A generation bump invalidates the (ordinal, generation)-keyed cell
        // string decode; the render path recomputes it and output is stable.
        $model->debugBumpGeneration($ordinal);
        $second = $renderer->renderDocument($model);

        self::assertSame($first, $second);
    }

    public function testPolicyChangeNeverServesStaleCellHtml(): void
    {
        $renderer = new HtmlDocumentRenderer();
        // One rich cell carrying both policy-sensitive constructs: inline raw
        // HTML and an unsafe URL scheme.
        $model = $this->model("| H |\n| --- |\n| <b onclick=\"x()\">j</b> [j](javascript:alert(1)) |\n");
        $spec = new RenderOptions(htmlPolicy: HtmlPolicy::spec());

        Instrumentation::reset();
        $safeFirst = $renderer->renderDocument($model);
        $specFirst = $renderer->renderDocument($model, $spec);
        $safeSecond = $renderer->renderDocument($model);
        $specSecond = $renderer->renderDocument($model, $spec);

        self::assertStringContainsString('&lt;b onclick=&quot;x()&quot;&gt;', $safeFirst, 'safe must escape raw HTML');
        self::assertStringContainsString('<a href="">j</a>', $safeFirst, 'safe must empty the javascript: href');
        self::assertStringContainsString('<b onclick="x()">j</b>', $specFirst, 'spec must pass raw HTML through');
        self::assertStringContainsString('<a href="javascript:alert(1)">j</a>', $specFirst, 'spec must keep the href');

        self::assertSame($safeFirst, $safeSecond, 'safe warm render must be byte-identical');
        self::assertSame($specFirst, $specSecond, 'spec warm render must be byte-identical');

        // Two policies, one cell string: one miss per policy namespace, then
        // one hit per repeat render; no cross-policy bytes ever served.
        self::assertSame(2, Instrumentation::$tableCellHtmlMisses);
        self::assertSame(2, Instrumentation::$tableCellHtmlHits);
    }

    private function model(string $markdown): ParsedDocumentModel
    {
        $model = Markdown::gfm()->fromString($markdown)->model();

        if (!$model instanceof ParsedDocumentModel) {
            self::fail('Expected a parsed document model.');
        }

        return $model;
    }
}
