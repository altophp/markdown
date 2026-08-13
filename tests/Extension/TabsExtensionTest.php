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

use Alto\Markdown\Extension\Tabs\TabsExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

final class TabsExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::disable();
        Instrumentation::reset();
    }

    public function testTabsRenderMarkdownThroughDirectAndDocumentLanes(): void
    {
        $factory = Markdown::github()->with(new TabsExtension());
        $source = <<<'MARKDOWN'
            @tabs
            @tab PHP & <JS>
            # Heading

            **Strong** and [link](/docs).

            - one
            - two

            ```php
            echo 1;
            ```
            @tab Table
            | A | B |
            | - | - |
            | 1 | 2 |
            @endtabs
            MARKDOWN;
        $source .= "\n";

        $html = $factory->toHtml($source);

        self::assertStringContainsString(
            '<a class="markdown-tabs-tab is-active" id="markdown-tabs-1-tab-1"'
            . ' href="#markdown-tabs-1-panel-1" aria-controls="markdown-tabs-1-panel-1">'
            . 'PHP &amp; &lt;JS&gt;</a>',
            $html,
        );
        self::assertStringContainsString("<h1>Heading</h1>\n", $html);
        self::assertStringContainsString(
            '<p><strong>Strong</strong> and <a href="/docs">link</a>.</p>',
            $html,
        );
        self::assertStringContainsString("<ul>\n<li>one</li>\n<li>two</li>\n</ul>", $html);
        self::assertStringContainsString(
            "<pre><code class=\"language-php\">echo 1;\n</code></pre>",
            $html,
        );
        self::assertStringContainsString(
            "<table>\n<thead>\n<tr>\n<th>A</th>\n<th>B</th>\n</tr>\n</thead>",
            $html,
        );

        $document = $factory->fromString($source);
        self::assertSame($html, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame($source, $document->toMarkdown(new RenderOptions()));
        self::assertCount(1, $document->query()->kind(TabsExtension::GROUP_KIND)->get()->all());
        self::assertCount(2, $document->query()->kind(TabsExtension::ITEM_KIND)->get()->all());
    }

    public function testNestedTabsUseOneAdditionalMarkerPerLevel(): void
    {
        $factory = Markdown::commonmark()->with(new TabsExtension());
        $source = <<<'MARKDOWN'
            @tabs
            @tab Outer
            Before.

            @@tabs
            @@tab One
            Nested **one**.
            @@tab Two
            Nested two.
            @@endtabs

            After.
            @tab Other
            Other.
            @endtabs
            MARKDOWN;
        $source .= "\n";

        $document = $factory->fromString($source);
        $html = $document->toHtml();

        self::assertCount(2, $document->query()->kind(TabsExtension::GROUP_KIND)->get()->all());
        self::assertCount(4, $document->query()->kind(TabsExtension::ITEM_KIND)->get()->all());
        self::assertSame(2, substr_count($html, 'class="markdown-tabs"'));
        self::assertStringContainsString('<div class="markdown-tabs" id="markdown-tabs-2">', $html);
        self::assertStringContainsString('<p>Nested <strong>one</strong>.</p>', $html);
        self::assertStringContainsString(
            "</div>\n</div>\n<p>After.</p>\n</div>\n"
            . '<div class="markdown-tabs-panel" id="markdown-tabs-1-panel-2"',
            $html,
        );
        self::assertSame($source, $document->toMarkdown());
    }

    public function testOrphanMalformedEmptyAndUnclosedGroupsAreConservative(): void
    {
        $factory = Markdown::commonmark()->with(new TabsExtension());

        self::assertSame("<p>@tab Orphan</p>\n", $factory->toHtml("@tab Orphan\n"));
        self::assertSame("<p>@tabs extra</p>\n", $factory->toHtml("@tabs extra\n"));
        self::assertSame('', $factory->toHtml("@tabs\n@endtabs\n"));
        self::assertStringContainsString(
            "<p>Body</p>\n</div>\n</div>\n</div>\n",
            $factory->toHtml("@tabs\n@tab Open\nBody\n"),
        );
        self::assertSame(
            '<p>' . str_repeat('@', 33) . "tabs</p>\n",
            $factory->toHtml(str_repeat('@', 33) . "tabs\n"),
        );

        $source = "@tabs\nIgnored before the first item.\n@tab Kept\nBody\n@endtabs\n";
        self::assertStringNotContainsString('Ignored before', $factory->toHtml($source));
        self::assertSame($source, $factory->fromString($source)->toMarkdown());
    }

    public function testInvalidNestedMarkersAndTitlesStayInTheCurrentPanel(): void
    {
        $factory = Markdown::commonmark()->with(new TabsExtension());
        $source = "@tabs\n@tab Valid\n"
            . "@@@tabs\n"
            . '@tab ' . str_repeat('x', 257) . "\n"
            . "@tab \"unclosed\n"
            . "@tab \"bad\x7Ftitle\"\n"
            . "Body\n@endtabs\n";
        $html = $factory->toHtml($source);

        self::assertStringContainsString('<p>@@@tabs', $html);
        self::assertStringContainsString('@tab ' . str_repeat('x', 257), $html);
        self::assertStringContainsString('@tab &quot;unclosed', $html);
        self::assertStringContainsString("@tab &quot;bad\x7Ftitle&quot;", $html);
        self::assertSame(1, substr_count($html, 'class="markdown-tabs-panel is-active"'));
    }

    public function testTabTitlesAndBodiesFollowTheActiveHtmlPolicy(): void
    {
        $factory = Markdown::commonmark()->with(new TabsExtension());
        $source = <<<'MARKDOWN'
            @tabs
            @tab "Safe <script>alert(1)</script>"
            <script>alert(2)</script>

            <strong>Allowed</strong>
            @endtabs
            MARKDOWN;
        $source .= "\n";
        $html = $factory->toHtml(
            $source,
            renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
        );

        self::assertStringContainsString(
            'Safe &lt;script&gt;alert(1)&lt;/script&gt;</a>',
            $html,
        );
        self::assertStringContainsString('<strong>Allowed</strong>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('aria-controls="markdown-tabs-1-panel-1"', $html);
        self::assertStringContainsString('aria-labelledby="markdown-tabs-1-tab-1"', $html);
    }

    public function testInactiveSyntaxAndPlainTextCostStayUnchanged(): void
    {
        $source = "Plain paragraph without extension triggers.\n";
        $extension = new TabsExtension();

        self::assertSame([], $extension->blockConstructs());
        self::assertSame([], $extension->nativeHtmlBlockRenderers());
        self::assertSame([], $extension->nativeMarkdownBlockPrinters());

        Instrumentation::measure();
        self::assertSame("<p>Plain paragraph without extension triggers.</p>\n", Markdown::commonmark()->toHtml($source));
        $coreBlockAttempts = Instrumentation::$blockTryStartCalls;

        Instrumentation::reset();
        self::assertSame(
            "<p>Plain paragraph without extension triggers.</p>\n",
            Markdown::commonmark()->with(new TabsExtension())->toHtml($source),
        );

        self::assertSame($coreBlockAttempts, Instrumentation::$blockTryStartCalls);
        self::assertSame("<p>@tabs</p>\n", Markdown::commonmark()->toHtml("@tabs\n"));
    }
}
