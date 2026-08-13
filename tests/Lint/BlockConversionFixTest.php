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

final class BlockConversionFixTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    public function testPreferFencedCodeBlocksFixSaveReparseRelintLoop(): void
    {
        $path = $this->writeTempFile("    echo hi\n");
        $file = Markdown::github()->open($path);
        $report = $file->lint((new LintConfig())->withRule('prefer-fenced-code-blocks'));

        self::assertCount(1, $report);
        self::assertNotNull($report->problems[0]->fix);

        $file->fix((new LintConfig())->withRule('prefer-fenced-code-blocks'));
        $file->save();

        $reparsed = Markdown::github()->open($path);
        $block = $reparsed->codeBlocks()->first();
        self::assertNotNull($block);

        self::assertSame("```\necho hi\n```\n", file_get_contents($path));
        self::assertTrue($reparsed->lint((new LintConfig())->withRule('prefer-fenced-code-blocks'))->isClean());
        self::assertSame("echo hi\n", $block->code());
    }

    public function testPreferFencedCodeBlocksLeavesFenceCollisionReportOnly(): void
    {
        $file = Markdown::github()->fromString("    ```\n    echo hi\n");
        $report = $file->lint((new LintConfig())->withRule('prefer-fenced-code-blocks'));

        self::assertCount(1, $report);
        self::assertNull($report->problems[0]->fix);

        $file->fix((new LintConfig())->withRule('prefer-fenced-code-blocks'));

        self::assertTrue($file->model()->journal()->isEmpty());
        self::assertTrue($file->diff()->isEmpty());
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
        $path = \sys_get_temp_dir() . '/alto-markdown-block-conversion-fix-' . \bin2hex(\random_bytes(8)) . '.md';
        $this->paths[] = $path;

        return $path;
    }
}
