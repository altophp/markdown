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

namespace Alto\Markdown\Tests\Conformance;

use Alto\Markdown\Profile\GfmProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use PHPUnit\Framework\TestCase;

final class GfmStrikethroughRendererTest extends TestCase
{
    public function testGfmRendersDoubleTildeAsStrikethrough(): void
    {
        $renderer = new TestHtmlRenderer((new ProfileCompiler())->compile(new GfmProfile()));

        self::assertSame(
            "<p><del>Hi</del> Hello, <a href=\"/url\"><del>world</del></a>!</p>\n",
            $renderer->render("~~Hi~~ Hello, [~~world~~](/url)!\n"),
        );
    }

    public function testGfmDoesNotSpanParagraphs(): void
    {
        $renderer = new TestHtmlRenderer((new ProfileCompiler())->compile(new GfmProfile()));

        self::assertSame(
            "<p>This ~~has a</p>\n<p>new paragraph~~.</p>\n",
            $renderer->render("This ~~has a\n\nnew paragraph~~.\n"),
        );
    }

    public function testCommonMarkKeepsDoubleTildeLiteral(): void
    {
        self::assertSame(
            "<p>~~Hi~~ Hello, world!</p>\n",
            new TestHtmlRenderer()->render("~~Hi~~ Hello, world!\n"),
        );
    }
}
