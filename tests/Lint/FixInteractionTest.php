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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixInteractionTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    public function testRepeatedFixPassBeforeSaveIsNoOpAndPreviewDiffIsStable(): void
    {
        $file = Markdown::github()->open($this->writeTempFile("line   \nlast"));

        $file->fix((new LintConfig())->withRule('no-trailing-spaces')->withRule('final-newline'));
        $firstDiff = $file->diff()->toUnifiedString();
        $firstEntries = $file->model()->journal()->entries();

        $file->fix((new LintConfig())->withRule('no-trailing-spaces')->withRule('final-newline'));

        self::assertSame($firstDiff, $file->diff()->toUnifiedString());
        self::assertSame($firstEntries, $file->model()->journal()->entries());
    }

    public function testPairwiseFixesAreDeterministicAndSaveClearsJournal(): void
    {
        $path = $this->writeTempFile("Visit https://example.com   \n```\necho hi\n```\n");
        $file = Markdown::github()->open($path);

        $file
            ->fix((new LintConfig())
            ->withRule('no-bare-urls')
            ->withRule('no-trailing-spaces')
            ->withRule('require-code-block-language'));

        $firstDiff = $file->diff()->toUnifiedString();
        $secondDiff = $file->diff()->toUnifiedString();

        self::assertSame($firstDiff, $secondDiff);

        $file->save();
        $reparsed = Markdown::github()->open($path);

        self::assertSame("Visit <https://example.com>  \n```text\necho hi\n```\n", file_get_contents($path));
        self::assertTrue($file->model()->journal()->isEmpty());
        self::assertTrue($file->diff()->isEmpty());
        self::assertTrue($reparsed->lint((new LintConfig())->withRule('no-bare-urls')->withRule('no-trailing-spaces')->withRule('require-code-block-language'))->isClean());
    }

    #[DataProvider('fixRulePairs')]
    public function testEveryFixableRulePairIsOrderIndependentAndReachesAFixedPoint(
        string $firstRule,
        string $secondRule,
    ): void {
        $source = "Visit https://example.com   \n\n    echo indented\n\n```\necho fenced\n```";
        $forward = $this->fixedBytes($source, $firstRule, $secondRule);
        $reverse = $this->fixedBytes($source, $secondRule, $firstRule);

        self::assertSame($forward, $reverse);

        $path = $this->writeTempFile($forward);
        $file = Markdown::github()->open($path);
        $config = (new LintConfig())->withRule($firstRule)->withRule($secondRule);

        self::assertTrue($file->lint($config)->isClean());

        $file->fix($config);

        self::assertTrue($file->model()->journal()->isEmpty());
        self::assertTrue($file->diff()->isEmpty());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fixRulePairs(): iterable
    {
        yield 'bare URL and code language' => ['no-bare-urls', 'require-code-block-language'];
        yield 'bare URL and fenced code' => ['no-bare-urls', 'prefer-fenced-code-blocks'];
        yield 'bare URL and trailing spaces' => ['no-bare-urls', 'no-trailing-spaces'];
        yield 'bare URL and final newline' => ['no-bare-urls', 'final-newline'];
        yield 'code language and fenced code' => ['require-code-block-language', 'prefer-fenced-code-blocks'];
        yield 'code language and trailing spaces' => ['require-code-block-language', 'no-trailing-spaces'];
        yield 'code language and final newline' => ['require-code-block-language', 'final-newline'];
        yield 'fenced code and trailing spaces' => ['prefer-fenced-code-blocks', 'no-trailing-spaces'];
        yield 'fenced code and final newline' => ['prefer-fenced-code-blocks', 'final-newline'];
        yield 'trailing spaces and final newline' => ['no-trailing-spaces', 'final-newline'];
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

    private function fixedBytes(string $source, string $firstRule, string $secondRule): string
    {
        $path = $this->writeTempFile($source);
        $file = Markdown::github()->open($path);
        $file->fix((new LintConfig())->withRule($firstRule)->withRule($secondRule));
        $file->save();

        return (string) file_get_contents($path);
    }

    private function tempPath(): string
    {
        $path = \sys_get_temp_dir().'/alto-markdown-fix-interaction-'.\bin2hex(\random_bytes(8)).'.md';
        $this->paths[] = $path;

        return $path;
    }
}
