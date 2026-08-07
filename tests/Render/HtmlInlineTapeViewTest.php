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
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\TestCase;

final class HtmlInlineTapeViewTest extends TestCase
{
    public function testRichBlockRenderingUsesOneCachedTapeView(): void
    {
        Instrumentation::reset();
        $document = Markdown::gfm()->fromString("*emphasis*, **strong**, ~~strike~~, and [link](/target).\n");
        $expected = "<p><em>emphasis</em>, <strong>strong</strong>, <del>strike</del>, and <a href=\"/target\">link</a>.</p>\n";

        self::assertSame($expected, $document->toHtml());
        self::assertSame(1, Instrumentation::$inlineParses);
        self::assertSame(1, Instrumentation::$inlineContentBuilds);
        self::assertSame(0, Instrumentation::$inlineMarkdownReconstructions);
        self::assertSame(0, Instrumentation::$cacheHits);

        self::assertSame($expected, $document->toHtml());
        self::assertSame(1, Instrumentation::$inlineParses);
        self::assertSame(1, Instrumentation::$inlineContentBuilds);
        self::assertSame(0, Instrumentation::$inlineMarkdownReconstructions);
        self::assertSame(1, Instrumentation::$cacheHits);
    }

    public function testPlainBlockScansSourceWithoutBuildingInlineContent(): void
    {
        $factory = Markdown::gfm();
        Instrumentation::reset();

        self::assertSame("<p>plain text</p>\n", $factory->toHtml("plain text\n"));
        self::assertSame(0, Instrumentation::$inlineParses);
        self::assertSame(0, Instrumentation::$inlineContentBuilds);
        self::assertSame(0, Instrumentation::$inlineMarkdownReconstructions);
    }
}
