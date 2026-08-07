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
use Alto\Markdown\Lint\LintSeverity;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdditionalStructuralRulesTest extends TestCase
{
    public function testConsistentTableColumnsReportsEveryRaggedBodyRowAtItsExactSourceRange(): void
    {
        $source = <<<'MD'
            | A | B |
            | --- | --- |
            | one |
            | one | two | three |
            | a\|b | c |
            | `x|y` | z |

            MD;
        $report = Markdown::gfm()->fromString($source)->lint(
            new LintConfig(['consistent-table-columns']),
        );

        self::assertCount(2, $report);
        self::assertSame(
            ['| one |', '| one | two | three |'],
            array_map(
                static fn ($problem): string => substr(
                    $source,
                    $problem->range->startOffset,
                    $problem->range->endOffset - $problem->range->startOffset,
                ),
                $report->problems,
            ),
        );
        self::assertSame(
            ['Table row has 1 columns; expected 2.', 'Table row has 3 columns; expected 2.'],
            array_column($report->problems, 'message'),
        );
        self::assertNull($report->problems[0]->fix);
        self::assertNull($report->problems[1]->fix);
    }

    public function testConsistentTableColumnsKeepsCrLfAndContainerMarkersOutsideTheRange(): void
    {
        $source = "> | A | B |\r\n> | --- | --- |\r\n> | only |\r\n";
        $report = Markdown::gfm()->fromString($source)->lint(
            new LintConfig(['consistent-table-columns']),
        );

        self::assertCount(1, $report);
        self::assertSame(
            '| only |',
            substr(
                $source,
                $report->problems[0]->range->startOffset,
                $report->problems[0]->range->endOffset - $report->problems[0]->range->startOffset,
            ),
        );
    }

    public function testConsistentTableColumnsKeepsCrOnlyListMarkersOutsideTheRange(): void
    {
        $source = "- | A | B |\r  | --- | --- |\r  | only |\r";
        $report = Markdown::gfm()->fromString($source)->lint(
            new LintConfig(['consistent-table-columns']),
        );

        self::assertCount(1, $report);
        self::assertSame(
            '| only |',
            substr(
                $source,
                $report->problems[0]->range->startOffset,
                $report->problems[0]->range->endOffset - $report->problems[0]->range->startOffset,
            ),
        );
    }

    public function testConsistentTableColumnsDoesNotGuessAtNonTables(): void
    {
        $source = "| A | B |\n| broken |\n| one |\n";
        $config = new LintConfig(['consistent-table-columns']);

        self::assertTrue(Markdown::gfm()->fromString($source)->lint($config)->isClean());
        self::assertTrue(Markdown::commonmark()->fromString("| A |\n| --- |\n| one | two |\n")->lint($config)->isClean());
    }

    #[DataProvider('closedFenceProvider')]
    public function testRequireClosedCodeFenceAcceptsValidClosers(string $source): void
    {
        $report = Markdown::commonmark()->fromString($source)->lint(
            new LintConfig(['require-closed-code-fence']),
        );

        self::assertTrue($report->isClean());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function closedFenceProvider(): iterable
    {
        yield 'root LF' => ["```php\ncode\n```\n"];
        yield 'closer at EOF' => ["```php\ncode\n```"];
        yield 'longer closer' => ["````php\ncode\n``````\n"];
        yield 'indented tilde' => ["   ~~~php\n   code\n  ~~~~\n"];
        yield 'block quote' => ["> ```php\n> code\n> ```\n"];
        yield 'root CR-only' => ["```php\rcode\r```\r"];
        yield 'list container CRLF' => ["- ```php\r\n  code\r\n  ```\r\n"];
    }

    #[DataProvider('unclosedFenceProvider')]
    public function testRequireClosedCodeFenceReportsTheExactOpeningMarker(string $source, string $marker): void
    {
        $report = Markdown::commonmark()->fromString($source)->lint(
            new LintConfig(
                enabledRules: ['require-closed-code-fence'],
                severityByRule: ['require-closed-code-fence' => LintSeverity::Warning],
            ),
        );

        self::assertCount(1, $report);
        self::assertSame($marker, substr(
            $source,
            $report->problems[0]->range->startOffset,
            $report->problems[0]->range->endOffset - $report->problems[0]->range->startOffset,
        ));
        self::assertSame(LintSeverity::Warning, $report->problems[0]->severity);
        self::assertNull($report->problems[0]->fix);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unclosedFenceProvider(): iterable
    {
        yield 'root' => ["```php\ncode\n", '```'];
        yield 'wrong marker' => ["```php\ncode\n~~~\n", '```'];
        yield 'short closer' => ["````php\ncode\n```\n", '````'];
        yield 'over-indented closer' => ["```php\ncode\n    ```\n", '```'];
        yield 'container ends' => ["> ~~~php\n> code\noutside\n", '~~~'];
        yield 'list container CR-only' => ["1. ~~~php\r   code\r", '~~~'];
    }

    public function testRequireClosedCodeFenceDoesNotReportOtherCodeOrFenceLikeParagraphs(): void
    {
        $source = "    code\n\n``` invalid ` info\nparagraph\n";
        $report = Markdown::commonmark()->fromString($source)->lint(
            new LintConfig(['require-closed-code-fence']),
        );

        self::assertTrue($report->isClean());
    }

    public function testRequireClosedCodeFenceDoesNotTreatAMutatedIndentedBlockAsSourceFence(): void
    {
        $document = Markdown::commonmark()->fromString("    old code\n");
        $block = $document->codeBlocks()->first();
        self::assertNotNull($block);
        $block->replaceCode("new code\n");

        self::assertTrue($document->lint(
            new LintConfig(['require-closed-code-fence']),
        )->isClean());
    }

    public function testAdditionalRulesShareOneTraversal(): void
    {
        $source = "| A | B |\n| --- | --- |\n| one |\n\n```\ncode\n";
        $document = Markdown::gfm()->fromString($source);

        Instrumentation::reset();
        $report = $document->lint(new LintConfig([
            'consistent-table-columns',
            'require-closed-code-fence',
        ]));

        self::assertCount(2, $report);
        self::assertSame(1, Instrumentation::$traversals);
    }
}
