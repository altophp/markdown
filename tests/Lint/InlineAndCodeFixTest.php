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

final class InlineAndCodeFixTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    public function testNoBareUrlsFixSaveReparseRelintLoop(): void
    {
        $path = $this->writeTempFile("Visit https://example.com and <https://example.org>.\n");
        $file = Markdown::github()->open($path);

        $file->fix((new LintConfig())->withRule('no-bare-urls'));
        $file->save();

        self::assertSame("Visit <https://example.com> and <https://example.org>.\n", file_get_contents($path));
        self::assertTrue(Markdown::github()->open($path)->lint((new LintConfig())->withRule('no-bare-urls'))->isClean());
    }

    public function testRequireCodeBlockLanguageFixTargetsOpeningFenceOnly(): void
    {
        $path = $this->writeTempFile("```\necho hi\n```\n");
        $file = Markdown::github()->open($path);

        $file->fix((new LintConfig())->withRule('require-code-block-language'));

        $entry = $file->model()->journal()->entries()[0];
        $range = $entry->affectedRange;
        self::assertNotNull($range);
        self::assertSame(3, $range->startOffset);
        self::assertSame(3, $range->endOffset);

        $file->save();

        $reparsed = Markdown::github()->open($path);

        self::assertSame("```text\necho hi\n```\n", file_get_contents($path));
        self::assertTrue($reparsed->lint((new LintConfig())->withRule('require-code-block-language'))->isClean());
        $block = $reparsed->codeBlocks('text')->first();
        self::assertNotNull($block);
        self::assertSame('text', $block->language());
        self::assertSame("echo hi\n", $block->code());
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
        $path = \sys_get_temp_dir() . '/alto-markdown-inline-code-fix-' . \bin2hex(\random_bytes(8)) . '.md';
        $this->paths[] = $path;

        return $path;
    }
}
