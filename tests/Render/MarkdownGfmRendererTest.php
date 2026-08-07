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

final class MarkdownGfmRendererTest extends TestCase
{
    public function testGithubExtensionCorpusRoundTrips(): void
    {
        $comparison = new MarkdownRoundTripHarness(Markdown::github())->compareCorpus([
            'table' => "| a | b |\n| --- | ---: |\n| c | d |\n",
            'table-pipe' => "| f\\|oo |\n| --- |\n| b `|` az |\n",
            'task-list' => "- [ ] todo\n- [x] done\n",
            'strikethrough' => "~~gone~~ and here\n",
            'alert' => "> [!NOTE]\n> Body\n",
        ]);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testCommonMarkDoesNotPromoteTableSyntax(): void
    {
        $document = Markdown::commonmark()->fromString("| a |\n| --- |\n");

        self::assertSame(0, $document->stats()->tableCount);
        self::assertSame("\\| a \\|\n\\| \\-\\-\\- \\|\n", $document->toMarkdown(new RenderOptions()));
    }

    public function testNormalizedTableKeepsColumnAlignments(): void
    {
        $document = Markdown::gfm()->fromString(
            "| Left | Center | Right |\n| :--- | :---: | ---: |\n| A | B | C |\n",
        );

        self::assertSame(
            "| Left | Center | Right |\n| :--- | :---: | ---: |\n| A | B | C |\n",
            $document->toMarkdown(new RenderOptions()),
        );
    }

    public function testCommonMarkDoesNotPromoteTaskListSyntax(): void
    {
        self::assertSame(
            "- \\[x\\] done\n",
            Markdown::commonmark()->fromString("- [x] done\n")->toMarkdown(new RenderOptions()),
        );
    }

    public function testCommonMarkDoesNotPromoteStrikethroughSyntax(): void
    {
        self::assertSame(
            "\\~\\~gone\\~\\~\n",
            Markdown::commonmark()->fromString("~~gone~~\n")->toMarkdown(new RenderOptions()),
        );
    }

    public function testGfmDoesNotPromoteGithubAlertSyntax(): void
    {
        self::assertSame(
            "> \\[\\!NOTE\\]\n> Body\n",
            Markdown::gfm()->fromString("> [!NOTE]\n> Body\n")->toMarkdown(new RenderOptions()),
        );
    }
}
