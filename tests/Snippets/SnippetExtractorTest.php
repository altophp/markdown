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

namespace Alto\Markdown\Tests\Snippets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SnippetExtractorTest extends TestCase
{
    public function testExtractsSinglePhpBlockWithLineNumber(): void
    {
        $markdown = "# Title\n\nIntro paragraph.\n\n```php\n\$x = 1;\n```\n\nDone.\n";

        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(1, $snippets);
        self::assertSame(5, $snippets[0]->line);
        self::assertSame("\$x = 1;\n", $snippets[0]->code);
    }

    public function testIgnoresNonPhpFencedBlocks(): void
    {
        $markdown = "```js\nconst x = 1;\n```\n\n```\nplain\n```\n\n```python\npass\n```\n";

        self::assertSame([], (new SnippetExtractor())->extract($markdown));
    }

    public function testIgnoresPhpLookingFenceInsideMarkdownExample(): void
    {
        $markdown = "````markdown\n```php\necho 1;\n```\n````\n";

        self::assertSame([], (new SnippetExtractor())->extract($markdown));
    }

    public function testMatchesTildeFences(): void
    {
        $markdown = "~~~php\n\$y = 2;\n~~~\n";

        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(1, $snippets);
        self::assertSame("\$y = 2;\n", $snippets[0]->code);
    }

    public function testInfoStringWithTrailingWordsStillMatches(): void
    {
        $markdown = "```php hl_lines=\"1\"\n\$z = 3;\n```\n";

        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(1, $snippets);
        self::assertSame("\$z = 3;\n", $snippets[0]->code);
    }

    public function testDoesNotMatchInfoStringThatOnlyStartsWithPhpLetters(): void
    {
        $markdown = "```phpstan\nnot code\n```\n";

        self::assertSame([], (new SnippetExtractor())->extract($markdown));
    }

    public function testDedentsIndentedOpeningFence(): void
    {
        $markdown = "- item\n\n  ```php\n  \$a = 1;\n  \$b = 2;\n  ```\n";

        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(1, $snippets);
        self::assertSame(3, $snippets[0]->line);
        self::assertSame("\$a = 1;\n\$b = 2;\n", $snippets[0]->code);
    }

    public function testLongerClosingFenceClosesBlock(): void
    {
        $markdown = "````php\n\$a = 1;\n````\n";

        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(1, $snippets);
        self::assertSame("\$a = 1;\n", $snippets[0]->code);
    }

    public function testShorterFenceInsideDoesNotClose(): void
    {
        $markdown = "````php\n```\nstill inside\n```\n````\n";

        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(1, $snippets);
        self::assertSame("```\nstill inside\n```\n", $snippets[0]->code);
    }

    public function testUnclosedBlockClosesAtEndOfInput(): void
    {
        $markdown = "```php\n\$a = 1;\n\$b = 2;\n";

        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(1, $snippets);
        self::assertSame("\$a = 1;\n\$b = 2;\n", $snippets[0]->code);
    }

    public function testMultipleBlocksTrackSeparateLines(): void
    {
        $markdown = "```php\nfirst();\n```\n\ntext\n\n```php\nsecond();\n```\n";

        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(2, $snippets);
        self::assertSame(1, $snippets[0]->line);
        self::assertSame("first();\n", $snippets[0]->code);
        self::assertSame(7, $snippets[1]->line);
        self::assertSame("second();\n", $snippets[1]->code);
    }

    #[DataProvider('provideCrlfInputs')]
    public function testNormalizesCarriageReturns(string $markdown): void
    {
        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(1, $snippets);
        self::assertSame("\$a = 1;\n", $snippets[0]->code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCrlfInputs(): iterable
    {
        yield 'crlf' => ["```php\r\n\$a = 1;\r\n```\r\n"];
        yield 'lf' => ["```php\n\$a = 1;\n```\n"];
    }

    public function testEmptyBlockYieldsEmptyCode(): void
    {
        $markdown = "```php\n```\n";

        $snippets = (new SnippetExtractor())->extract($markdown);

        self::assertCount(1, $snippets);
        self::assertSame('', $snippets[0]->code);
    }
}
