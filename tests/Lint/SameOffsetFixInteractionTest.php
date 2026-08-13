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

final class SameOffsetFixInteractionTest extends TestCase
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

    public function testLanguageAndFinalNewlineInsertionsComposeAtEndOfFile(): void
    {
        $source = "- -  c\n\t```";
        $expected = "- -  c\n\t```text\n";
        $path = $this->writeTempFile($source);
        $file = Markdown::github()->open($path);

        $file->fix((new LintConfig())->withRule('require-code-block-language')->withRule('final-newline'));
        $firstDiff = $file->diff()->toUnifiedString();
        $firstEntries = $file->model()->journal()->entries();

        self::assertStringContainsString("+\t```text\n", $firstDiff);

        $file->fix((new LintConfig())->withRule('require-code-block-language')->withRule('final-newline'));

        self::assertSame($firstDiff, $file->diff()->toUnifiedString());
        self::assertSame($firstEntries, $file->model()->journal()->entries());

        $file->save();
        $reparsed = Markdown::github()->open($path);
        $report = $reparsed->lint((new LintConfig())->withRule('require-code-block-language')->withRule('final-newline'));

        self::assertSame($expected, file_get_contents($path));
        self::assertTrue($report->isClean());

        $reparsed->fix((new LintConfig())->withRule('require-code-block-language')->withRule('final-newline'));

        self::assertTrue($reparsed->model()->journal()->isEmpty());
        self::assertTrue($reparsed->diff()->isEmpty());
    }

    public function testSeparateFixCallsComposeInEitherOrderAndRemainIdempotent(): void
    {
        foreach ([
            ['```', "```text\n"],
            ["before\r\n```", "before\r\n```text\r\n"],
            ["before\r```", "before\r```text\r"],
        ] as [$source, $expected]) {
            foreach ([
                ['final-newline', 'require-code-block-language'],
                ['require-code-block-language', 'final-newline'],
            ] as [$firstRule, $secondRule]) {
                $path = $this->writeTempFile($source);
                $file = Markdown::github()->open($path);

                $file->fix((new LintConfig())->withRule($firstRule));
                $file->fix((new LintConfig())->withRule($secondRule));
                $firstDiff = $file->diff()->toUnifiedString();
                $firstEntries = $file->model()->journal()->entries();

                self::assertStringContainsString('text', $firstDiff);

                $file->fix((new LintConfig())->withRule($firstRule));
                $file->fix((new LintConfig())->withRule($secondRule));

                self::assertSame($firstDiff, $file->diff()->toUnifiedString());
                self::assertSame($firstEntries, $file->model()->journal()->entries());

                $file->save();
                $reparsed = Markdown::github()->open($path);

                self::assertSame($expected, file_get_contents($path));
                self::assertTrue($reparsed->lint((new LintConfig())->withRule('require-code-block-language')->withRule('final-newline'))->isClean());
            }
        }
    }

    private function writeTempFile(string $source): string
    {
        $path = sys_get_temp_dir() . '/alto-markdown-eof-fix-' . bin2hex(random_bytes(8)) . '.md';
        $this->paths[] = $path;
        file_put_contents($path, $source);

        return $path;
    }
}
