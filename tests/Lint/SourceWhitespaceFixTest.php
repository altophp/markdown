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

final class SourceWhitespaceFixTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    public function testFinalNewlineFixSaveReparseRelintLoop(): void
    {
        $path = $this->writeTempFile('no final newline');
        $file = Markdown::github()->open($path);

        $returned = $file->fix((new LintConfig())->withRule('final-newline'));

        self::assertSame($file, $returned);
        self::assertStringContainsString("+no final newline\n", $file->diff()->toUnifiedString());

        $file->save();

        self::assertSame("no final newline\n", file_get_contents($path));
        self::assertTrue($file->model()->journal()->isEmpty());
        self::assertTrue(Markdown::github()->open($path)->lint((new LintConfig())->withRule('final-newline'))->isClean());
    }

    public function testNoTrailingSpacesFixSaveReparseRelintLoopPreservesHardBreaks(): void
    {
        $path = $this->writeTempFile("ok  \nproblem   \nnext\t\n");
        $file = Markdown::github()->open($path);

        $file->fix((new LintConfig())->withRule('no-trailing-spaces'));
        $file->save();

        self::assertSame("ok  \nproblem  \nnext\n", file_get_contents($path));
        self::assertTrue(Markdown::github()->open($path)->lint((new LintConfig())->withRule('no-trailing-spaces'))->isClean());
    }

    public function testWhitespaceFixesPreserveCrLfAndBomThroughSave(): void
    {
        $path = $this->writeTempFile("\xEF\xBB\xBFline \r\nlast");
        $file = Markdown::github()->open($path);

        $file->fix((new LintConfig())->withRule('no-trailing-spaces')->withRule('final-newline'));
        $file->save();

        self::assertSame("\xEF\xBB\xBFline\r\nlast\r\n", file_get_contents($path));
        self::assertTrue(Markdown::github()->open($path)->lint((new LintConfig())->withRule('no-trailing-spaces')->withRule('final-newline'))->isClean());
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function writeTempFile(string $bytes): string
    {
        $path = $this->tempPath();
        file_put_contents($path, $bytes);

        return $path;
    }

    private function tempPath(): string
    {
        $path = \sys_get_temp_dir() . '/alto-markdown-whitespace-fix-' . \bin2hex(\random_bytes(8)) . '.md';
        $this->paths[] = $path;

        return $path;
    }
}
