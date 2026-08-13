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
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\TestCase;

final class LinkRulesTest extends TestCase
{
    public function testNoDeadAnchorsCatchesBrokenRealReadmeFragment(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../fixtures/readme-symfony.md');
        $document = Markdown::github()->fromString($source . "\n[broken](#not-in-this-readme)\n");

        $report = $document->lint((new LintConfig())->withRule('no-dead-anchor'));

        self::assertSame(['no-dead-anchor'], self::ruleIds($report->problems));
    }

    public function testNoEmptyLinksReportsEmptyDestination(): void
    {
        $report = Markdown::github()->fromString('[empty]() and ![image]().')->lint((new LintConfig())->withRule('no-empty-links'));

        self::assertSame(['no-empty-links', 'no-empty-links'], self::ruleIds($report->problems));
    }

    public function testRequireImageAltReportsEmptyInlineAndReferenceLabelsWithExactRanges(): void
    {
        $source = "Before ![](/inline.png), ![` `](/empty-code.png), and ![][logo].\n\n[logo]: /reference.png\n";
        $report = Markdown::github()->fromString($source)->lint((new LintConfig())->withRule('require-image-alt'));

        self::assertSame(
            ['require-image-alt', 'require-image-alt', 'require-image-alt'],
            self::ruleIds($report->problems),
        );
        self::assertSame(
            ['![](/inline.png)', '![` `](/empty-code.png)', '![][logo]'],
            array_map(
                static fn($problem): string => substr(
                    $source,
                    $problem->range->startOffset,
                    $problem->range->endOffset - $problem->range->startOffset,
                ),
                $report->problems,
            ),
        );
        self::assertNull($report->problems[0]->fix);
        self::assertNull($report->problems[1]->fix);
        self::assertNull($report->problems[2]->fix);
    }

    public function testRequireImageAltAcceptsPlainAndFormattedAlternativeText(): void
    {
        $source = '![Diagram](/plain.png) ![**Build** `status`](/formatted.png)';
        $report = Markdown::github()->fromString($source)->lint((new LintConfig())->withRule('require-image-alt'));

        self::assertTrue($report->isClean());
    }

    public function testNoBareUrlsReportsGfmExtendedAutolinksOnlyWhenBare(): void
    {
        $report = Markdown::github()->fromString('Visit https://example.com and <https://example.org>.')->lint((new LintConfig())->withRule('no-bare-urls'));

        self::assertSame(['no-bare-urls'], self::ruleIds($report->problems));
        self::assertStringContainsString('Bare URL', $report->problems[0]->message);
    }

    public function testDeadReferenceDefinitionsReportsUnusedDefinitions(): void
    {
        $report = Markdown::github()->fromString(<<<'MD'
            [used]: https://example.com
            [unused]: https://unused.example

            [ok][used]
            MD)->lint((new LintConfig())->withRule('no-dead-reference-definitions'));

        self::assertSame(['no-dead-reference-definitions'], self::ruleIds($report->problems));
        self::assertStringContainsString('unused', $report->problems[0]->message);
    }

    public function testLinkRulesUseInlineTraversalWithoutLinkHandles(): void
    {
        $document = Markdown::github()->fromString('Visit https://example.com, [empty](), and ![](/image.png).');

        Instrumentation::reset();
        $report = $document->lint(
            (new LintConfig())
                ->withRule('no-bare-urls')
                ->withRule('no-empty-links')
                ->withRule('require-image-alt'),
        );

        self::assertCount(3, $report);
        self::assertSame(1, Instrumentation::$traversals);
        self::assertSame(0, Instrumentation::$linkHandles);
        self::assertSame(0, Instrumentation::$imageHandles);
    }

    /**
     * @param list<\Alto\Markdown\Lint\LintProblem> $problems
     *
     * @return list<string>
     */
    private static function ruleIds(array $problems): array
    {
        return array_map(
            static fn(\Alto\Markdown\Lint\LintProblem $problem): string => $problem->ruleId,
            $problems,
        );
    }
}
