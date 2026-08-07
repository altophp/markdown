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

namespace Alto\Markdown\Tests\Formatter;

use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\MarkdownStyle;
use PHPUnit\Framework\TestCase;

final class FormatterSourcePassTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    public function testFormatterMatchesLintFixerForSourceWhitespaceRules(): void
    {
        $source = "ok  \nproblem   \nlast";

        self::assertSame($this->lintFixedBytes($source), $this->formattedBytes($source));
        self::assertSame("ok  \nproblem  \nlast\n", $this->formattedBytes($source));
    }

    public function testFormatterSourcePassesAreByteIdempotent(): void
    {
        $path = $this->writeTempFile("problem \nlast");
        $file = Markdown::github()->open($path);

        $file->format();
        $file->save();
        $first = file_get_contents($path);

        $secondFile = Markdown::github()->open($path);
        $secondFile->format();
        $secondFile->save();
        $second = file_get_contents($path);

        self::assertSame($first, $second);
        self::assertSame("problem\nlast\n", $second);
    }

    public function testFormatterCanDisableFinalNewlinePolicy(): void
    {
        $path = $this->writeTempFile("problem \nlast");
        $file = Markdown::github()->open($path);

        $file->format(new MarkdownStyle(finalNewline: false));
        $file->save();

        self::assertSame("problem\nlast", file_get_contents($path));
    }

    public function testFormatterPreservesTrailingSpacesInsideCodePayloads(): void
    {
        $source = "```text\nfenced  \n```\n\n    indented  \n\n``\ninline code \n``\n";

        self::assertSame($source, $this->formattedBytes($source));
    }

    public function testFormatterMatchesLintOnFenceSyntaxAndPreservesPayload(): void
    {
        $source = "```   \ncode   \n```   \n";
        $expected = "```\ncode   \n```\n";

        self::assertSame($expected, $this->formattedBytes($source));
        self::assertSame($this->lintFixedBytes($source), $this->formattedBytes($source));
    }

    public function testFormatterAndLintShareOpaqueAndCrLfTrailingSpaceBehavior(): void
    {
        $source = "<pre>\r\nraw   \r\n</pre>\r\n\r\n```text\r\ncode   \r\n```\r\nparagraph   \r\nnext\r\n";
        $formatted = $this->formattedBytes($source);

        self::assertSame($this->lintFixedBytes($source), $formatted);
        self::assertSame($formatted, $this->formattedBytes($formatted));
    }

    public function testFormatterNormalizesTrailingSpacesWithCrOnlyLineEndings(): void
    {
        $source = "problem   \rnext\r";

        self::assertSame("problem  \rnext\r", $this->formattedBytes($source));
    }

    public function testFormatterPreservesTrailingSpacesInsideRawHtml(): void
    {
        $source = "<pre>\nalpha   \n</pre>\n";

        self::assertSame($source, $this->formattedBytes($source));
    }

    public function testFormatterPreservesTrailingSpacesInsideInlineRawHtml(): void
    {
        $source = "before <!-- raw   \nstill raw --> after\n";

        self::assertSame($source, $this->formattedBytes($source));
    }

    public function testFormatterOnlyPreservesHardBreakSpacesInParagraphs(): void
    {
        $source = "# Heading   \n\nparagraph    \nnext\n";

        self::assertSame("# Heading\n\nparagraph  \nnext\n", $this->formattedBytes($source));
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function lintFixedBytes(string $source): string
    {
        $path = $this->writeTempFile($source);
        $file = Markdown::github()->open($path);
        $file->fix((new LintConfig())->withRule('no-trailing-spaces')->withRule('final-newline'));
        $file->save();

        return (string) file_get_contents($path);
    }

    private function formattedBytes(string $source): string
    {
        $path = $this->writeTempFile($source);
        $file = Markdown::github()->open($path);
        $file->format();
        $file->save();

        return (string) file_get_contents($path);
    }

    private function writeTempFile(string $bytes): string
    {
        $path = $this->tempPath();
        file_put_contents($path, $bytes);

        return $path;
    }

    private function tempPath(): string
    {
        $path = \sys_get_temp_dir().'/alto-markdown-formatter-source-'.\bin2hex(\random_bytes(8)).'.md';
        $this->paths[] = $path;

        return $path;
    }
}
