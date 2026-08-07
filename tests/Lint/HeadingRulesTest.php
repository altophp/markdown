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

final class HeadingRulesTest extends TestCase
{
    public function testSingleH1ReportsSecondTopLevelHeading(): void
    {
        $report = Markdown::github()->fromString(<<<'MD'
            # Title

            ## Section

            # Second
            MD)->lint((new LintConfig())->withRule('single-h1'));

        self::assertSame(['single-h1'], self::ruleIds($report->problems));
        self::assertStringContainsString('single top-level heading', $report->problems[0]->message);
    }

    public function testNoSkippedHeadingLevelsReportsJump(): void
    {
        $report = Markdown::github()->fromString(<<<'MD'
            # Title

            ### Too deep
            MD)->lint((new LintConfig())->withRule('no-skipped-heading-levels'));

        self::assertSame(['no-skipped-heading-levels'], self::ruleIds($report->problems));
        self::assertStringContainsString('jumps from 1 to 3', $report->problems[0]->message);
    }

    public function testRequireTitleReportsDocumentWithoutInitialH1(): void
    {
        $report = Markdown::github()->fromString(<<<'MD'
            Intro paragraph.

            # Late title
            MD)->lint((new LintConfig())->withRule('require-title'));

        self::assertSame(['require-title'], self::ruleIds($report->problems));
        self::assertSame(0, $report->problems[0]->range->startOffset);
    }

    public function testRequireTitleReportsAnEmptyDocumentAtOffsetZero(): void
    {
        $report = Markdown::github()->fromString('')
            ->lint((new LintConfig())->withRule('require-title'));

        self::assertSame(['require-title'], self::ruleIds($report->problems));
        self::assertEquals(new \Alto\Markdown\Source\SourceRange(0, 0), $report->problems[0]->range);
    }

    public function testNoDuplicateHeadingsReportsDuplicateText(): void
    {
        $report = Markdown::github()->fromString(<<<'MD'
            # Title

            ## Install

            ### install
            MD)->lint((new LintConfig())->withRule('no-duplicate-headings'));

        self::assertSame(['no-duplicate-headings'], self::ruleIds($report->problems));
        self::assertStringContainsString('Duplicate heading "install"', $report->problems[0]->message);
    }

    public function testRecommendedIncludesHeadingRules(): void
    {
        $report = Markdown::github()->fromString(<<<'MD'
            Intro paragraph.

            # Late title

            ### Too deep

            # Second
            MD)->lint(LintConfig::recommended());

        self::assertSame([
            'require-title',
            'no-skipped-heading-levels',
            'single-h1',
        ], self::ruleIds($report->problems));
    }

    /**
     * @param list<\Alto\Markdown\Lint\LintProblem> $problems
     *
     * @return list<string>
     */
    private static function ruleIds(array $problems): array
    {
        return array_map(
            static fn (\Alto\Markdown\Lint\LintProblem $problem): string => $problem->ruleId,
            $problems,
        );
    }
}
