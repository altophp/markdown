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
use Alto\Markdown\Lint\Options\RequireCodeBlockLanguageOptions;
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\CodeBlock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeAndSourceRulesTest extends TestCase
{
    public function testRequireCodeBlockLanguageReportsEmptyFenceInfo(): void
    {
        $report = Markdown::github()->fromString("```\necho hi\n```\n")->lint((new LintConfig())->withRule('require-code-block-language'));

        self::assertSame(['require-code-block-language'], self::ruleIds($report->problems));
    }

    public function testRequireCodeBlockLanguageAcceptsLanguage(): void
    {
        $report = Markdown::github()->fromString("```php\necho hi\n```\n")->lint((new LintConfig())->withRule('require-code-block-language'));

        self::assertTrue($report->isClean());
    }

    public function testRequireCodeBlockLanguageUsesTypedDefaultLanguageOption(): void
    {
        $config = new LintConfig(
            enabledRules: ['require-code-block-language'],
            optionsByRule: [
                'require-code-block-language' => new RequireCodeBlockLanguageOptions('plaintext'),
            ],
        );
        $document = Markdown::github()->fromString("```\necho hi\n```\n");

        $document->fix($config);

        self::assertSame("```plaintext\necho hi\n```\n", $document->toMarkdown());
    }

    public function testRequireCodeBlockLanguageCanDisableTheDefaultFix(): void
    {
        $config = new LintConfig(
            enabledRules: ['require-code-block-language'],
            optionsByRule: [
                'require-code-block-language' => new RequireCodeBlockLanguageOptions(null),
            ],
        );
        $report = Markdown::github()->fromString("```\necho hi\n```\n")->lint($config);

        self::assertCount(1, $report);
        self::assertNull($report->problems[0]->fix);
    }

    public function testMutatedIndentedCodeReportsLanguageWithoutUnsafeSourceFix(): void
    {
        $document = Markdown::github()->fromString("    echo old\n");
        $block = $document->codeBlocks()->first();
        self::assertNotNull($block);
        $block->replaceCode("echo new\n");

        $report = $document->lint(
            (new LintConfig())->withRule('require-code-block-language'),
        );

        self::assertCount(1, $report);
        self::assertNull($report->problems[0]->fix);
    }

    #[DataProvider('codeFenceInfoSpacingProvider')]
    public function testCodeFenceInfoSpacingFixesOnlyTheOpeningGap(string $source, string $expected): void
    {
        $config = (new LintConfig())->withRule('code-fence-info-spacing');
        $document = Markdown::github()->fromString($source);
        $report = $document->lint($config);

        self::assertCount(1, $report);
        self::assertSame('code-fence-info-spacing', $report->problems[0]->ruleId);
        self::assertSame(" \t ", substr(
            $source,
            $report->problems[0]->range->startOffset,
            $report->problems[0]->range->endOffset - $report->problems[0]->range->startOffset,
        ));
        self::assertNotNull($report->problems[0]->fix);

        $document->fix($config);
        $codeBlock = $document->codeBlocks()->first();

        self::assertSame($expected, $document->toMarkdown());
        self::assertInstanceOf(CodeBlock::class, $codeBlock);
        self::assertSame('php', $codeBlock->language());
        self::assertSame("code  \n", str_replace(["\r\n", "\r"], "\n", $codeBlock->code()));
        self::assertTrue(Markdown::github()->fromString($document->toMarkdown())->lint($config)->isClean());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function codeFenceInfoSpacingProvider(): iterable
    {
        yield 'backtick LF' => [
            "``` \t php title=value\ncode  \n```\n",
            "```php title=value\ncode  \n```\n",
        ];
        yield 'indented tilde CRLF' => [
            "  ~~~ \t php title=value\r\n  code  \r\n  ~~~\r\n",
            "  ~~~php title=value\r\n  code  \r\n  ~~~\r\n",
        ];
        yield 'backtick CR' => [
            "``` \t php title=value\rcode  \r```\r",
            "```php title=value\rcode  \r```\r",
        ];
    }

    public function testCodeFenceInfoSpacingAcceptsCanonicalAndEmptyInfoStrings(): void
    {
        $config = (new LintConfig())->withRule('code-fence-info-spacing');

        self::assertTrue(Markdown::github()->fromString("```php title=value\ncode\n```\n")->lint($config)->isClean());
        self::assertTrue(Markdown::github()->fromString("``` \t \ncode\n```\n")->lint($config)->isClean());
    }

    public function testPreferFencedCodeBlocksReportsIndentedCode(): void
    {
        $report = Markdown::github()->fromString("    echo hi\n")->lint((new LintConfig())->withRule('prefer-fenced-code-blocks'));

        self::assertSame(['prefer-fenced-code-blocks'], self::ruleIds($report->problems));
    }

    public function testNoTrailingSpacesReportsExactRangeButAllowsHardBreakDoubleSpace(): void
    {
        $source = "ok  \nproblem   \nnext\t\n";
        $report = Markdown::github()->fromString($source)->lint((new LintConfig())->withRule('no-trailing-spaces'));

        self::assertSame(['no-trailing-spaces', 'no-trailing-spaces'], self::ruleIds($report->problems));
        self::assertSame([12, 15], [$report->problems[0]->range->startOffset, $report->problems[0]->range->endOffset]);
        self::assertSame([20, 21], [$report->problems[1]->range->startOffset, $report->problems[1]->range->endOffset]);
    }

    public function testFinalNewlineReportsEndOffset(): void
    {
        $report = Markdown::github()->fromString('no final newline')->lint((new LintConfig())->withRule('final-newline'));

        self::assertSame(['final-newline'], self::ruleIds($report->problems));
        self::assertSame(16, $report->problems[0]->range->startOffset);
        self::assertSame(16, $report->problems[0]->range->endOffset);
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
