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
use Alto\Markdown\Tests\Support\MarkdownCorpus;
use PHPUnit\Framework\TestCase;

final class FixerCorpusTest extends TestCase
{
    private const array FIXABLE_RULES = [
        'no-trailing-spaces',
        'final-newline',
        'no-bare-urls',
        'require-code-block-language',
        'prefer-fenced-code-blocks',
    ];

    private const array EXPECTED_REPORT_ONLY = [
        'divergence-case-007' => ['prefer-fenced-code-blocks'],
        'divergence-case-008' => ['prefer-fenced-code-blocks'],
        'divergence-case-037' => ['prefer-fenced-code-blocks'],
        'divergence-case-039' => ['prefer-fenced-code-blocks'],
    ];

    public function testTrailingSpaceFixPreservesRenderedMeaningAcrossCorpus(): void
    {
        $failures = [];

        foreach (MarkdownCorpus::realAndGeneratedDocuments() as $name => $source) {
            if ('huge-generated-corpus' === $name) {
                continue;
            }

            try {
                $original = Markdown::github()->fromString($source);
                $path = tempnam(\sys_get_temp_dir(), 'alto-markdown-whitespace-corpus-');

                if (false === $path) {
                    throw new \RuntimeException('Unable to create whitespace corpus temporary file.');
                }

                try {
                    if (false === file_put_contents($path, $source)) {
                        throw new \RuntimeException('Unable to seed whitespace corpus temporary file.');
                    }

                    $file = Markdown::github()->open($path);
                    $file->fix((new LintConfig())->withRule('no-trailing-spaces'));
                    $file->save();
                    $reparsed = Markdown::github()->open($path);

                    if ($original->toHtml() !== $reparsed->toHtml()) {
                        $failures[] = $name . ': rendered HTML changed after trailing-space fix.';
                    }
                } finally {
                    @unlink($path);
                }
            } catch (\Throwable $error) {
                $failures[] = $name . ': ' . $error::class . ': ' . $error->getMessage();
            }
        }

        self::assertSame(
            [],
            $failures,
            \sprintf(
                "Trailing-space semantic failures across %d documents:\n%s",
                MarkdownCorpus::SEMANTIC_DOCUMENTS,
                implode("\n", $failures),
            ),
        );
    }

    public function testFixableRulesSaveReparseLeaveNoFixableProblemsAndReachFixedPoint(): void
    {
        $failures = [];
        $reportOnly = [];

        foreach (MarkdownCorpus::realAndGeneratedDocuments() as $name => $source) {
            $path = tempnam(\sys_get_temp_dir(), 'alto-markdown-fixer-corpus-');

            if (false === $path) {
                self::fail('Unable to create fixer corpus temporary file.');
            }

            try {
                if (false === file_put_contents($path, $source)) {
                    throw new \RuntimeException('Unable to seed temporary Markdown file.');
                }

                $file = Markdown::github()->open($path);
                $file->fix($this->fixableConfig());
                $file->save();
                $firstBytes = $this->read($path);

                if (!$file->model()->journal()->isEmpty()) {
                    $failures[] = $name . ': save did not clear the first-pass journal.';
                }

                $reparsed = Markdown::github()->open($path);
                $report = $reparsed->lint($this->fixableConfig());

                foreach ($report as $problem) {
                    if (null !== $problem->fix) {
                        $failures[] = $name . ': relint still has fixable problem ' . $problem->ruleId . '.';

                        continue;
                    }

                    $reportOnly[$name][] = $problem->ruleId;
                }

                $reparsed->fix($this->fixableConfig());

                if (!$reparsed->model()->journal()->isEmpty()) {
                    $failures[] = $name . ': second fix pass produced operations.';
                }

                if (!$reparsed->diff()->isEmpty()) {
                    $failures[] = $name . ': second fix pass produced a diff.';
                }

                $reparsed->save();

                if ($firstBytes !== $this->read($path)) {
                    $failures[] = $name . ': second fix-save pass changed bytes.';
                }
            } catch (\Throwable $error) {
                $failures[] = $name . ': ' . $error::class . ': ' . $error->getMessage();
            } finally {
                @unlink($path);
            }
        }

        if (self::EXPECTED_REPORT_ONLY !== $reportOnly) {
            $failures[] = 'report-only corpus changed: ' . json_encode($reportOnly, \JSON_THROW_ON_ERROR) . '.';
        }

        self::assertSame(
            [],
            $failures,
            \sprintf(
                "Fixer failures across %d documents and %d fixable rules:\n%s",
                MarkdownCorpus::REAL_AND_GENERATED_DOCUMENTS,
                \count(self::FIXABLE_RULES),
                implode("\n", $failures),
            ),
        );
    }

    private function fixableConfig(): LintConfig
    {
        $config = new LintConfig();

        foreach (self::FIXABLE_RULES as $rule) {
            $config = $config->withRule($rule);
        }

        return $config;
    }

    private function read(string $path): string
    {
        $bytes = file_get_contents($path);

        if (false === $bytes) {
            throw new \RuntimeException('Unable to read fixer corpus temporary file.');
        }

        return $bytes;
    }
}
