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
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression gate for indented code inside containers.
 *
 * An indented code block strips exactly four columns past the container's
 * content indentation, not four columns from column zero. Both directions of
 * the old defect are pinned here: lines that used to keep the container's own
 * indentation as leading spaces, and lines whose partially consumed tab used to
 * lose the columns it still owed. The examples are pinned against the spec
 * corpora so a renderer change cannot quietly reintroduce either direction, and
 * every one runs through both product lanes (fused direct emission and the
 * inline tape walk), which share the block content reader.
 */
final class IndentedCodeContainerHtmlTest extends TestCase
{
    /**
     * CommonMark examples that exercise indented code under a block quote or a
     * list item, plus the tab cases where a straddling tab must survive.
     *
     * @var list<int>
     */
    private const array COMMONMARK_EXAMPLES = [
        5, 6, 7, 236, 252, 254, 264, 270, 271, 273, 274, 278, 286, 287, 288, 290,
    ];

    /**
     * The same family as numbered in the GFM corpus.
     *
     * @var list<int>
     */
    private const array GFM_EXAMPLES = [
        5, 6, 7, 214, 230, 232, 242, 248, 249, 251, 252, 256, 264, 265, 266, 268,
    ];

    /**
     * @param list<int> $wanted
     */
    #[DataProvider('provideCorpora')]
    public function testIndentedCodeInContainersMatchesTheSpec(string $fixture, string $profile, array $wanted): void
    {
        $examples = self::loadExamples($fixture, $wanted);
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());
        $factory = self::factory($profile);
        $failures = [];

        foreach ($examples as $example) {
            foreach (self::lanes($factory, $example['markdown'], $options) as $lane => $html) {
                if ($html !== $example['html']) {
                    $failures[] = \sprintf(
                        "example %d (%s lane):\nmarkdown: %s\nexpected: %s\nactual:   %s",
                        $example['example'],
                        $lane,
                        var_export($example['markdown'], true),
                        var_export($example['html'], true),
                        var_export($html, true),
                    );
                }
            }
        }

        self::assertSame([], $failures, \sprintf(
            '%d indented-code container renderings diverge from %s.',
            \count($failures),
            $fixture,
        ));
        self::assertCount(\count($wanted), $examples);
    }

    /**
     * A container prefix that only partially consumes a tab owes the remaining
     * columns to the code line: they must survive as real spaces rather than be
     * swallowed with the rest of the indentation.
     */
    #[DataProvider('provideSurvivingTabColumns')]
    public function testPartiallyConsumedTabKeepsItsRemainingColumns(string $markdown, string $expected): void
    {
        $factory = Markdown::commonmark();
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());

        self::assertSame($expected, $factory->toHtml($markdown, renderOptions: $options));
        self::assertSame($expected, $factory->fromString($markdown)->toHtml($options));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSurvivingTabColumns(): iterable
    {
        yield 'block quote with two tabs' => [
            ">\t\tfoo\n",
            "<blockquote>\n<pre><code>  foo\n</code></pre>\n</blockquote>\n",
        ];

        yield 'loose list item with two tabs' => [
            "- foo\n\n\t\tbar\n",
            "<ul>\n<li>\n<p>foo</p>\n<pre><code>  bar\n</code></pre>\n</li>\n</ul>\n",
        ];

        yield 'list marker followed by two tabs' => [
            "-\t\tfoo\n",
            "<ul>\n<li>\n<pre><code>  foo\n</code></pre>\n</li>\n</ul>\n",
        ];
    }

    /**
     * The mirror direction: the container's own content indentation is not part
     * of the code, so none of it may leak into the rendered block.
     */
    #[DataProvider('provideStrippedContainerIndent')]
    public function testContainerIndentDoesNotLeakIntoCode(string $markdown, string $expected): void
    {
        $factory = Markdown::commonmark();
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());

        self::assertSame($expected, $factory->toHtml($markdown, renderOptions: $options));
        self::assertSame($expected, $factory->fromString($markdown)->toHtml($options));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideStrippedContainerIndent(): iterable
    {
        yield 'bullet item indented code' => [
            "- foo\n\n      bar\n",
            "<ul>\n<li>\n<p>foo</p>\n<pre><code>bar\n</code></pre>\n</li>\n</ul>\n",
        ];

        yield 'ordered item with wide marker' => [
            "1.  A paragraph\n    with two lines.\n\n        indented code\n",
            "<ol>\n<li>\n<p>A paragraph\nwith two lines.</p>\n<pre><code>indented code\n</code></pre>\n</li>\n</ol>\n",
        ];

        yield 'block quote indented code' => [
            ">     code\n\n>    not code\n",
            "<blockquote>\n<pre><code>code\n</code></pre>\n</blockquote>\n<blockquote>\n<p>not code</p>\n</blockquote>\n",
        ];
    }

    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function provideCorpora(): iterable
    {
        yield 'spec_tests.json / commonmark' => ['spec_tests.json', 'commonmark', self::COMMONMARK_EXAMPLES];
        yield 'gfm_tests.json / gfm' => ['gfm_tests.json', 'gfm', self::GFM_EXAMPLES];
    }

    /**
     * @return array<string, string>
     */
    private static function lanes(MarkdownFactory $factory, string $markdown, RenderOptions $options): array
    {
        return [
            'direct' => $factory->toHtml($markdown, renderOptions: $options),
            'document' => $factory->fromString($markdown)->toHtml($options),
        ];
    }

    private static function factory(string $profile): MarkdownFactory
    {
        return match ($profile) {
            'commonmark' => Markdown::commonmark(),
            default => Markdown::gfm(),
        };
    }

    /**
     * @param list<int> $wanted
     *
     * @return list<array{example: int, markdown: string, html: string}>
     */
    private static function loadExamples(string $fixture, array $wanted): array
    {
        $path = \dirname(__DIR__) . '/fixtures/' . $fixture;
        $json = file_get_contents($path);

        if (false === $json) {
            self::fail(\sprintf('Cannot read fixture "%s".', $path));
        }

        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            self::fail(\sprintf('Fixture "%s" is not an example list.', $path));
        }

        $keep = array_flip($wanted);
        $examples = [];

        foreach ($decoded as $entry) {
            $example = \is_array($entry) ? ($entry['example'] ?? null) : null;
            $markdown = \is_array($entry) ? ($entry['markdown'] ?? null) : null;
            $html = \is_array($entry) ? ($entry['html'] ?? null) : null;

            if (!\is_int($example) || !\is_string($markdown) || !\is_string($html)) {
                self::fail(\sprintf('Fixture "%s" contains a malformed example.', $path));
            }

            if (isset($keep[$example])) {
                $examples[] = ['example' => $example, 'markdown' => $markdown, 'html' => $html];
            }
        }

        return $examples;
    }
}
