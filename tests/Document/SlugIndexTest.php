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

namespace Alto\Markdown\Tests\Document;

use Alto\Markdown\Document\GitHubSlugger;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\Heading;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugIndexTest extends TestCase
{
    #[DataProvider('provideSlugFixtures')]
    public function testGitHubSluggerFixtures(string $text, string $slug): void
    {
        self::assertSame($slug, GitHubSlugger::slug($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSlugFixtures(): iterable
    {
        yield 'punctuation' => ['Hello, world!', 'hello-world'];
        yield 'unicode' => ['Café déjà vu', 'café-déjà-vu'];
        yield 'markup text input' => ['Install Code & More', 'install-code-more'];
        yield 'hyphen kept' => ['API-v2 Reference', 'api-v2-reference'];
    }

    public function testDeadAnchorLintAcceptsDuplicateHeadingSlug(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Intro

            ## Intro

            See [duplicate](#intro-1).
            MD);

        self::assertTrue($document->lint((new LintConfig())->withRule('no-dead-anchor'))->isClean());
    }

    public function testDeadAnchorLintReportsMissingAnchorWithoutFix(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Intro

            See [missing](#missing-anchor).
            MD);

        $report = $document->lint((new LintConfig())->withRule('no-dead-anchor'));

        self::assertFalse($report->isClean());
        self::assertCount(1, $report->problems);
        self::assertContainsOnlyInstancesOf(LintProblem::class, $report->problems);
        self::assertSame('no-dead-anchor', $report->problems[0]->ruleId);
        self::assertNull($report->problems[0]->fix);
        self::assertStringContainsString('#missing-anchor', $report->problems[0]->message);
    }

    public function testSlugIndexWorksAcrossV1Profiles(): void
    {
        foreach ([Markdown::commonmark(), Markdown::gfm(), Markdown::github()] as $factory) {
            $document = $factory->fromString("# Café\n\n[ok](#café)\n");

            self::assertTrue($document->lint((new LintConfig())->withRule('no-dead-anchor'))->isClean());
        }
    }

    public function testHeadingAnchorAccessorIsNotPublicInV1(): void
    {
        self::assertFalse(method_exists(Heading::class, 'anchor'));
    }
}
