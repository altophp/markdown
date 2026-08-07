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

final class GfmTableRendererTest extends TestCase
{
    private function renderer(): TestHtmlRenderer
    {
        return new TestHtmlRenderer((new ProfileCompiler())->compile(new GfmProfile()));
    }

    public function testTableHeaderNeedsNoLeadingPipe(): void
    {
        self::assertSame(
            "<table>\n<thead>\n<tr>\n<th>foo</th>\n<th>bar</th>\n</tr>\n</thead>\n</table>\n",
            $this->renderer()->render("foo | bar\n--- | ---\n"),
        );
    }

    public function testEscapedPipeStaysInsideCellContent(): void
    {
        self::assertSame(
            "<table>\n<thead>\n<tr>\n<th>f|oo</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>b <code>|</code> az</td>\n</tr>\n</tbody>\n</table>\n",
            $this->renderer()->render("| f\\|oo |\n| --- |\n| b `\\|` az |\n"),
        );
    }

    public function testCodeSpanPipeUsesMatchingBacktickRunLength(): void
    {
        self::assertSame(
            "<table>\n<thead>\n<tr>\n<th><code>x | y</code></th>\n<th>z</th>\n</tr>\n</thead>\n</table>\n",
            $this->renderer()->render("| `` x | y `` | z |\n| --- | --- |\n"),
        );
    }

    public function testRaggedRowsArePaddedOrTruncated(): void
    {
        self::assertSame(
            "<table>\n<thead>\n<tr>\n<th>a</th>\n<th>b</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>one</td>\n<td></td>\n</tr>\n<tr>\n<td>x</td>\n<td>y</td>\n</tr>\n</tbody>\n</table>\n",
            $this->renderer()->render("| a | b |\n| --- | --- |\n| one |\n| x | y | z |\n"),
        );
    }

    public function testInvalidDelimiterFallsBackToParagraph(): void
    {
        self::assertSame(
            "<p>| a | b |\n| --- |\n| one |</p>\n",
            $this->renderer()->render("| a | b |\n| --- |\n| one |\n"),
        );
    }

    public function testListMarkerAfterTableStartsAList(): void
    {
        self::assertSame(
            "<table>\n<thead>\n<tr>\n<th>a</th>\n</tr>\n</thead>\n</table>\n<ul>\n<li>item</li>\n</ul>\n",
            $this->renderer()->render("| a |\n| --- |\n- item\n"),
        );
    }

    public function testThematicBreakAfterTableClosesTable(): void
    {
        self::assertSame(
            "<table>\n<thead>\n<tr>\n<th>a</th>\n</tr>\n</thead>\n</table>\n<hr />\n",
            $this->renderer()->render("| a |\n| --- |\n***\n"),
        );
    }
}
