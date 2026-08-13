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
use Alto\Markdown\Tests\Support\MarkdownRoundTripHarness;
use PHPUnit\Framework\TestCase;

final class MarkdownRoundTripCorpusTest extends TestCase
{
    public function testCommonMarkSupportedCorpusRoundTrips(): void
    {
        $comparison = new MarkdownRoundTripHarness(Markdown::commonmark())->compareCorpus([
            'cm-atx-heading' => "# Title\n\n###### Deep\n",
            'cm-paragraph-inline' => "Paragraph with *em* **strong** and `code`.\n",
            'cm-soft-hard-breaks' => "alpha\nbeta  \ngamma\\\ndelta\n",
            'cm-themed-break' => "***\n",
            'cm-fenced-code' => "```php\necho \"ok\";\n```\n",
            'cm-indented-code' => "    echo \"ok\";\n",
            'cm-blockquote' => "> # Quote\n>\n> Body\n",
            'cm-unordered-list' => "- one\n- two\n",
            'cm-ordered-start' => "3. three\n4. four\n",
            'cm-nested-list' => "- parent\n  - child\n",
            'cm-link-image' => "[site](https://example.com \"Title\") and ![alt](img.png)\n",
            'cm-inline-html' => "<span>raw</span>\n",
        ]);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testGfmSupportedCorpusRoundTrips(): void
    {
        $comparison = new MarkdownRoundTripHarness(Markdown::gfm())->compareCorpus([
            'gfm-table' => "| a | b |\n| --- | ---: |\n| c | d |\n",
            'gfm-table-pipe' => "| f\\|oo |\n| --- |\n| b `|` az |\n",
            'gfm-task-list' => "- [ ] todo\n- [x] done\n",
            'gfm-strikethrough' => "~~gone~~ and here\n",
            'gfm-extended-autolink' => "Visit www.commonmark.org and foo@bar.baz.\n",
        ]);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testGithubSupportedCorpusRoundTrips(): void
    {
        $comparison = new MarkdownRoundTripHarness(Markdown::github())->compareCorpus([
            'github-alert-note' => "> [!NOTE]\n> Body\n",
            'github-alert-same-line' => "> [!WARNING] Body\n",
        ]);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testRealFixtureSmokeRoundTripsSupportedPrefixes(): void
    {
        $comparison = new MarkdownRoundTripHarness(Markdown::github())->compareCorpus([
            'fixture-readme-react-prefix' => $this->fixturePrefix('readme-react.md', 6),
            'fixture-huge-prefix' => $this->fixturePrefix('huge.md', 8),
        ]);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    private function fixturePrefix(string $name, int $lines): string
    {
        $source = (string) file_get_contents(__DIR__ . '/../fixtures/' . $name);

        return implode("\n", \array_slice(explode("\n", $source), 0, $lines)) . "\n";
    }
}
