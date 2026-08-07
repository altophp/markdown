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

final class FinalNewlineEolFixTest extends TestCase
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

    public function testLfCrLfAndCrAreAcceptedAsExistingFinalEol(): void
    {
        foreach ([
            "line\n",
            "line\r\n",
            "line\r",
            "\xEF\xBB\xBFline\r",
        ] as $source) {
            $document = Markdown::github()->fromString($source);

            self::assertTrue($document->lint((new LintConfig())->withRule('final-newline'))->isClean());

            $document->fix((new LintConfig())->withRule('final-newline'));

            self::assertTrue($document->model()->journal()->isEmpty());
        }
    }

    public function testMissingFinalEolUsesTheDominantSourceEolAndPreservesBom(): void
    {
        foreach ([
            ["first\nlast", "first\nlast\n"],
            ["first\r\nlast", "first\r\nlast\r\n"],
            ["first\rlast", "first\rlast\r"],
            ["\xEF\xBB\xBFfirst\r\nlast", "\xEF\xBB\xBFfirst\r\nlast\r\n"],
        ] as [$source, $expected]) {
            $path = $this->writeTempFile($source);
            $file = Markdown::github()->open($path);
            $problem = $file->lint((new LintConfig())->withRule('final-newline'))->problems[0];

            self::assertSame(\strlen($source), $problem->range->startOffset);
            self::assertSame(\strlen($source), $problem->range->endOffset);

            $file->fix((new LintConfig())->withRule('final-newline'));
            $file->save();

            self::assertSame($expected, file_get_contents($path));
            self::assertTrue(Markdown::github()->open($path)->lint((new LintConfig())->withRule('final-newline'))->isClean());
        }
    }

    public function testLintFixerMatchesFormatterForEverySupportedEol(): void
    {
        foreach ([
            "first\nlast",
            "first\r\nlast",
            "first\rlast",
            "\xEF\xBB\xBFfirst\r\nlast",
        ] as $source) {
            self::assertSame($this->lintFixedBytes($source), $this->formattedBytes($source));
        }
    }

    private function lintFixedBytes(string $source): string
    {
        $path = $this->writeTempFile($source);
        $file = Markdown::github()->open($path);
        $file->fix((new LintConfig())->withRule('final-newline'));
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

    private function writeTempFile(string $source): string
    {
        $path = sys_get_temp_dir().'/alto-markdown-final-eol-'.bin2hex(random_bytes(8)).'.md';
        $this->paths[] = $path;
        file_put_contents($path, $source);

        return $path;
    }
}
