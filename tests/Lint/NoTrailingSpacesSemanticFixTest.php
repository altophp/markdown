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

namespace Alto\Markdown\Tests\Lint;

use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;
use PHPUnit\Framework\TestCase;

final class NoTrailingSpacesSemanticFixTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testFencedCodePayloadIsUntouchedWhileFenceAndParagraphWhitespaceIsFixed(): void
    {
        $source = "```   \ncode   \n```   \nafter   \n";
        $expected = "```\ncode   \n```\nafter  \n";

        $this->assertFixedBytes($source, $expected);
    }

    public function testIndentedCodePayloadIncludingInteriorBlankLinesIsUntouched(): void
    {
        $source = "    first   \n      \n    second\t\n\noutside   \n";
        $expected = "    first   \n      \n    second\t\n\noutside  \n";

        $this->assertFixedBytes($source, $expected);
    }

    public function testTwoSpacesArePreservedOnlyForParagraphHardBreaks(): void
    {
        $source = "# ATX heading  \n\nSetext heading  \n--------------\n\nParagraph hard break  \nnext\n";
        $expected = "# ATX heading\n\nSetext heading\n--------------\n\nParagraph hard break  \nnext\n";

        $this->assertFixedBytes($source, $expected);
    }

    public function testLongerParagraphSpaceRunsNormalizeToExactlyTwoSpaces(): void
    {
        $source = "two  \nthree   \nfour    \n";
        $expected = "two  \nthree  \nfour  \n";

        $this->assertFixedBytes($source, $expected);
    }

    public function testFencedCodePayloadAndParagraphHardBreakPreserveBomAndCrLf(): void
    {
        $source = "\xEF\xBB\xBF```   \r\ncode   \r\n```   \r\nparagraph  \r\nnext\r\n";
        $expected = "\xEF\xBB\xBF```\r\ncode   \r\n```\r\nparagraph  \r\nnext\r\n";

        $this->assertFixedBytes($source, $expected);
    }

    public function testHtmlBlockPayloadIsUntouchedWhileMarkdownOutsideItIsFixed(): void
    {
        $source = "<div>\nraw html   \n</div>\n\noutside   \n";
        $expected = "<div>\nraw html   \n</div>\n\noutside  \n";

        $this->assertFixedBytes($source, $expected);
    }

    public function testInlineHtmlPayloadIsUntouchedWhileSurroundingMarkdownIsFixed(): void
    {
        $source = "before <!--\nraw html   \n--> after   \n";
        $expected = "before <!--\nraw html   \n--> after  \n";

        $this->assertFixedBytes($source, $expected);
    }

    public function testLoneCrLineEndingsFixWhitespaceAndPreserveParagraphHardBreaks(): void
    {
        $source = "problem   \rnext\r";
        $expected = "problem  \rnext\r";
        $report = Markdown::github()->fromString($source)->lint((new LintConfig())->withRule('no-trailing-spaces'));

        self::assertCount(1, $report);
        self::assertSame([7, 10], [$report->problems[0]->range->startOffset, $report->problems[0]->range->endOffset]);

        $this->assertFixedBytes($source, $expected);
    }

    public function testLoneCrPreservesBomAndOpaqueCodeAndHtmlPayloads(): void
    {
        $source = "\xEF\xBB\xBF```   \rcode   \r```   \r<div>\rraw html   \r</div>\r\routside   \r";
        $expected = "\xEF\xBB\xBF```\rcode   \r```\r<div>\rraw html   \r</div>\r\routside  \r";

        $this->assertFixedBytes($source, $expected);
    }

    private function assertFixedBytes(string $source, string $expected): void
    {
        $path = $this->writeTempFile($source);
        $file = Markdown::github()->open($path);

        $file->fix((new LintConfig())->withRule('no-trailing-spaces'));
        $file->save();

        self::assertSame($expected, file_get_contents($path));
        self::assertTrue(Markdown::github()->open($path)->lint((new LintConfig())->withRule('no-trailing-spaces'))->isClean());
    }

    private function writeTempFile(string $bytes): string
    {
        $path = sys_get_temp_dir().'/alto-markdown-semantic-whitespace-'.bin2hex(random_bytes(8)).'.md';
        $this->paths[] = $path;
        file_put_contents($path, $bytes);

        return $path;
    }
}
