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
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Parser\ParseOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task list marker placement.
 *
 * The GFM marker is inline content of the item's first paragraph, so a
 * tight item emits it right after <li> and a loose item emits it inside
 * the paragraph. Every case runs through both HTML lanes (the fused
 * direct lane and the inline tape document lane) and both profiles that
 * enable task lists, and the expectations are the bytes cmark-gfm and
 * league/commonmark produce.
 */
final class TaskListHtmlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTaskLists(): iterable
    {
        yield 'tight' => [
            "- [ ] todo\n- [x] done\n",
            "<ul>\n<li><input disabled=\"\" type=\"checkbox\"> todo</li>\n<li><input checked=\"\" disabled=\"\" type=\"checkbox\"> done</li>\n</ul>\n",
        ];

        yield 'loose' => [
            "- [ ] task\n\n- [x] done\n",
            "<ul>\n<li>\n<p><input disabled=\"\" type=\"checkbox\"> task</p>\n</li>\n<li>\n<p><input checked=\"\" disabled=\"\" type=\"checkbox\"> done</p>\n</li>\n</ul>\n",
        ];

        yield 'loose with two paragraphs' => [
            "- [ ] one\n\n  two\n",
            "<ul>\n<li>\n<p><input disabled=\"\" type=\"checkbox\"> one</p>\n<p>two</p>\n</li>\n</ul>\n",
        ];

        yield 'loose checked with two paragraphs' => [
            "- [x] done\n\n  more\n",
            "<ul>\n<li>\n<p><input checked=\"\" disabled=\"\" type=\"checkbox\"> done</p>\n<p>more</p>\n</li>\n</ul>\n",
        ];

        yield 'loose with trailing code block' => [
            "- [ ] item\n\n  ```\n  code\n  ```\n",
            "<ul>\n<li>\n<p><input disabled=\"\" type=\"checkbox\"> item</p>\n<pre><code>code\n</code></pre>\n</li>\n</ul>\n",
        ];

        yield 'loose outer with tight nested list' => [
            "- [ ] outer\n\n  - [x] inner\n",
            "<ul>\n<li>\n<p><input disabled=\"\" type=\"checkbox\"> outer</p>\n<ul>\n<li><input checked=\"\" disabled=\"\" type=\"checkbox\"> inner</li>\n</ul>\n</li>\n</ul>\n",
        ];

        yield 'loose marker keeps its inline neighbours' => [
            "- [ ] *em* task\n\n- plain\n",
            "<ul>\n<li>\n<p><input disabled=\"\" type=\"checkbox\"> <em>em</em> task</p>\n</li>\n<li>\n<p>plain</p>\n</li>\n</ul>\n",
        ];

        yield 'loose ordered' => [
            "1. [ ] one\n\n2. [x] two\n",
            "<ol>\n<li>\n<p><input disabled=\"\" type=\"checkbox\"> one</p>\n</li>\n<li>\n<p><input checked=\"\" disabled=\"\" type=\"checkbox\"> two</p>\n</li>\n</ol>\n",
        ];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideTaskListProfiles(): iterable
    {
        foreach (self::provideTaskLists() as $name => [$markdown, $html]) {
            foreach (['gfm', 'github'] as $profile) {
                yield $name . ' / ' . $profile => [$markdown, $html, $profile];
            }
        }
    }

    #[DataProvider('provideTaskListProfiles')]
    public function testDocumentLaneRendersTaskMarkersInline(string $markdown, string $html, string $profile): void
    {
        self::assertSame($html, self::factory($profile)->fromString($markdown)->toHtml());
    }

    #[DataProvider('provideTaskListProfiles')]
    public function testDirectLaneMatchesTheDocumentLane(string $markdown, string $html, string $profile): void
    {
        self::assertSame($html, self::factory($profile)->toHtml($markdown));
    }

    /**
     * Past HtmlRendererEngine::STACK_LIMIT the walk switches from native
     * recursion to the explicit frame stack, which assembles list items on
     * its own code path. Marker placement must not depend on which path ran.
     */
    public function testFrameStackLaneKeepsTheMarkerInsideTheParagraph(): void
    {
        $depth = 520;
        $quote = str_repeat('> ', $depth);
        $markdown = $quote . "- [ ] task\n" . $quote . "\n" . $quote . "- [x] done\n";
        $options = (new ParseOptions())->withMaxNestingDepth(4096);

        $document = Markdown::gfm()->fromString($markdown, $options)->toHtml();

        self::assertSame(Markdown::gfm()->toHtml($markdown, $options), $document);
        self::assertStringContainsString(
            "<ul>\n<li>\n<p><input disabled=\"\" type=\"checkbox\"> task</p>\n</li>\n<li>\n<p><input checked=\"\" disabled=\"\" type=\"checkbox\"> done</p>\n</li>\n</ul>\n",
            $document,
        );
    }

    public function testCommonMarkLeavesLooseTaskMarkersLiteral(): void
    {
        self::assertSame(
            "<ul>\n<li>\n<p>[ ] task</p>\n</li>\n<li>\n<p>[x] done</p>\n</li>\n</ul>\n",
            Markdown::commonmark()->fromString("- [ ] task\n\n- [x] done\n")->toHtml(),
        );
    }

    private static function factory(string $profile): MarkdownFactory
    {
        return match ($profile) {
            'github' => Markdown::github(),
            default => Markdown::gfm(),
        };
    }
}
