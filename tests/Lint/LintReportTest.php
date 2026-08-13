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

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\Linter;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\LintReport;
use Alto\Markdown\Lint\LintRuleCategory;
use Alto\Markdown\Lint\LintRuleMetadata;
use Alto\Markdown\Lint\LintSeverity;
use Alto\Markdown\Lint\Options\RequireCodeBlockLanguageOptions;
use Alto\Markdown\Markdown;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class LintReportTest extends TestCase
{
    public function testReportSortsProblemsByOffsetThenRuleId(): void
    {
        $report = new LintReport([
            new LintProblem('b-rule', 'second', new SourceRange(10, 11)),
            new LintProblem('c-rule', 'third', new SourceRange(20, 21)),
            new LintProblem('a-rule', 'first', new SourceRange(10, 12)),
        ]);

        self::assertSame(['a-rule', 'b-rule', 'c-rule'], array_map(
            static fn(LintProblem $problem): string => $problem->ruleId,
            $report->problems,
        ));
    }

    public function testReportIsIterableCountableAndKnowsErrorSeverity(): void
    {
        $warning = new LintProblem('warn', 'warning', new SourceRange(0, 1), severity: LintSeverity::Warning);
        $error = new LintProblem('err', 'error', new SourceRange(2, 3));
        $report = new LintReport([$warning, $error]);

        self::assertCount(2, $report);
        self::assertSame([$warning, $error], iterator_to_array($report));
        self::assertFalse(new LintReport([$warning])->hasErrors());
        self::assertTrue($report->hasErrors());
    }

    public function testLintConfigEnablesRulesAndOverridesSeverity(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Intro

            See [missing](#missing-anchor).
            MD);

        $report = $document->lint(new LintConfig(
            enabledRules: ['no-dead-anchor'],
            severityByRule: ['no-dead-anchor' => LintSeverity::Warning],
        ));

        self::assertCount(1, $report);
        self::assertFalse($report->hasErrors());
        self::assertSame(LintSeverity::Warning, $report->problems[0]->severity);
    }

    public function testLintConfigBuilderIsImmutable(): void
    {
        $base = LintConfig::recommended();
        $custom = $base
            ->withoutRule('require-title')
            ->withRule('final-newline')
            ->withRule('final-newline')
            ->withSeverity('final-newline', LintSeverity::Warning);

        self::assertContains('require-title', $base->enabledRules);
        self::assertNotContains('require-title', $custom->enabledRules);
        self::assertSame(1, array_count_values($custom->enabledRules)['final-newline']);
        self::assertSame(LintSeverity::Warning, $custom->severityFor('final-newline'));
        self::assertSame(LintSeverity::Error, $base->severityFor('final-newline'));
    }

    public function testLintConfigOptionsAreImmutableAndRemovedWithTheRule(): void
    {
        $options = new RequireCodeBlockLanguageOptions('plaintext');
        $base = new LintConfig();
        $configured = $base
            ->withRule('require-code-block-language')
            ->withOptions('require-code-block-language', $options);

        self::assertNull($base->optionsFor('require-code-block-language'));
        self::assertSame($options, $configured->optionsFor('require-code-block-language'));
        self::assertSame($configured, $configured->withOptions('require-code-block-language', $options));
        self::assertNull($configured->withoutRule('require-code-block-language')->optionsFor('require-code-block-language'));
    }

    public function testCodeBlockLanguageOptionsRejectAnInvalidDefaultToken(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Code block language must be a non-empty token');

        new RequireCodeBlockLanguageOptions('plain text');
    }

    public function testLintRuleMetadataKeepsItsPositionalConstructorCompatible(): void
    {
        $metadata = new LintRuleMetadata(
            'custom-rule',
            'Custom rule',
            LintSeverity::Warning,
            true,
            false,
        );

        self::assertSame(LintRuleCategory::Style, $metadata->category);
        self::assertNull($metadata->optionsClass);
    }

    public function testLinterCanBeReusedAcrossDocuments(): void
    {
        $linter = new Linter((new LintConfig())->withRule('require-title'));

        self::assertTrue($linter->lint(Markdown::github()->fromString("# First\n"))->isClean());
        self::assertFalse($linter->lint(Markdown::github()->fromString("Body\n"))->isClean());
    }

    public function testLinterRejectsUnknownRule(): void
    {
        $linter = new Linter((new LintConfig())->withRule('not-a-rule'));

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Unknown lint rule "not-a-rule".');

        $linter->lint(Markdown::github()->fromString("# Title\n"));
    }
}
