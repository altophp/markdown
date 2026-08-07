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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlBlockFastPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function docs(): iterable
    {
        yield 'plain paragraph' => ["hello world\n", "<p>hello world</p>\n"];
        yield 'paragraph trailing spaces' => ["hello   \n", "<p>hello</p>\n"];
        yield 'paragraph gt quote' => ["a > \"b\"\n", "<p>a &gt; &quot;b&quot;</p>\n"];
        yield 'plain heading' => ["# Title here\n", "<h1>Title here</h1>\n"];
        yield 'heading amp' => ["## A & B\n", "<h2>A &amp; B</h2>\n"];
    }

    #[DataProvider('docs')]
    public function testPlainBlocksRenderIdentically(string $md, string $expected): void
    {
        self::assertSame($expected, Markdown::gfm()->fromString($md)->toHtml());
    }

    public function testRichAndMultilineBlocksStillFullyParsed(): void
    {
        // emphasis, autolink, and a soft break must all survive (fast path must decline)
        self::assertStringContainsString('<em>b</em>', Markdown::gfm()->fromString("a *b* c\n")->toHtml());
        self::assertStringContainsString('<a href="http://e.co">', Markdown::gfm()->fromString("go http://e.co\n")->toHtml());
        self::assertStringContainsString("one\ntwo", Markdown::gfm()->fromString("one\ntwo\n")->toHtml());
    }
}
