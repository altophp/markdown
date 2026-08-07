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
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Tests\Support\MarkdownRoundTripHarness;
use PHPUnit\Framework\TestCase;

final class MarkdownInlineRendererTest extends TestCase
{
    public function testTextEscapingTable(): void
    {
        $source = <<<'MD'
            \# heading
            1\. ordered
            \- bullet
            a | b
            \<x> &amp; \\ back
            MD;

        $expected = <<<'MD'
            \# heading
            1\. ordered
            \- bullet
            a \| b
            \<x\> \& \\ back
            MD;

        self::assertSame($expected."\n", Markdown::github()->fromString($source)->toMarkdown(new RenderOptions()));
    }

    public function testInlineConstructCorpusRoundTrips(): void
    {
        $comparison = new MarkdownRoundTripHarness(Markdown::github())->compareCorpus([
            'emphasis-and-strong' => "*em* **strong**\n",
            'code-span' => "`a  b` and `` `code` ``\n",
            'link-with-title' => "[x](https://example.com/a_b \"T\")\n",
            'image' => "![alt *text*](img.png \"T\")\n",
            'autolink' => "<https://example.com/a_b>\n",
            'inline-html' => "<span data-x=\"1\">raw</span>\n",
            'soft-break' => "alpha\nbeta\n",
            'hard-break-spaces' => "alpha  \nbeta\n",
            'hard-break-backslash' => "alpha\\\nbeta\n",
        ]);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testHardBreaksRenderAsBackslashBreaks(): void
    {
        self::assertSame(
            "alpha\\\nbeta\n",
            Markdown::github()->fromString("alpha  \nbeta\n")->toMarkdown(new RenderOptions()),
        );
    }
}
