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

final class GfmExtendedAutolinkRendererTest extends TestCase
{
    private function renderer(): TestHtmlRenderer
    {
        return new TestHtmlRenderer((new ProfileCompiler())->compile(new GfmProfile()));
    }

    public function testGfmRendersBareWwwAndUrlAutolinks(): void
    {
        self::assertSame(
            "<p>Visit <a href=\"http://www.commonmark.org/help\">www.commonmark.org/help</a> and <a href=\"https://example.org\">https://example.org</a>.</p>\n",
            $this->renderer()->render("Visit www.commonmark.org/help and https://example.org.\n"),
        );
    }

    public function testGfmTrimsTrailingPunctuationAndBalancesClosingParens(): void
    {
        self::assertSame(
            "<p>(<a href=\"http://www.google.com/search?q=Markup+(business)\">www.google.com/search?q=Markup+(business)</a>)</p>\n",
            $this->renderer()->render("(www.google.com/search?q=Markup+(business))\n"),
        );
    }

    public function testGfmTrimsEntityLookingSemicolonSuffix(): void
    {
        self::assertSame(
            "<p><a href=\"http://www.google.com/search?q=commonmark\">www.google.com/search?q=commonmark</a>&amp;hl;</p>\n",
            $this->renderer()->render("www.google.com/search?q=commonmark&hl;\n"),
        );
    }

    public function testGfmRendersEmailAndRejectsInvalidEmailDomainBoundaries(): void
    {
        self::assertSame(
            "<p>hello@mail+xyz.example isn't valid, but <a href=\"mailto:hello+xyz@mail.example\">hello+xyz@mail.example</a> is.</p>\n",
            $this->renderer()->render("hello@mail+xyz.example isn't valid, but hello+xyz@mail.example is.\n"),
        );
    }

    public function testCommonMarkKeepsBareAutolinksLiteral(): void
    {
        self::assertSame(
            "<p>Visit www.commonmark.org and foo@bar.baz.</p>\n",
            new TestHtmlRenderer()->render("Visit www.commonmark.org and foo@bar.baz.\n"),
        );
    }
}
