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

namespace Alto\Markdown\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecExampleLoaderTest extends TestCase
{
    public function testLoadsWellFormedExamplesPreservingOrder(): void
    {
        $json = <<<'JSON'
            [
              {"markdown": "a\n", "html": "<p>a</p>\n", "example": 1, "start_line": 1, "end_line": 3, "section": "Alpha"},
              {"markdown": "b\n", "html": "<p>b</p>\n", "example": 2, "start_line": 4, "end_line": 6, "section": "Beta"}
            ]
            JSON;

        $examples = (new SpecExampleLoader())->load($json);

        self::assertCount(2, $examples);

        self::assertSame("a\n", $examples[0]->markdown);
        self::assertSame("<p>a</p>\n", $examples[0]->html);
        self::assertSame(1, $examples[0]->example);
        self::assertSame('Alpha', $examples[0]->section);
        self::assertSame(1, $examples[0]->startLine);
        self::assertSame(3, $examples[0]->endLine);

        self::assertSame(2, $examples[1]->example);
        self::assertSame('Beta', $examples[1]->section);
    }

    public function testLoadsAnEmptyArray(): void
    {
        self::assertSame([], (new SpecExampleLoader())->load('[]'));
    }

    #[DataProvider('provideMalformedInput')]
    public function testThrowsOnMalformedInput(string $json): void
    {
        $this->expectException(MalformedSpecException::class);

        (new SpecExampleLoader())->load($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedInput(): iterable
    {
        yield 'not json' => ['this is not json'];
        yield 'truncated json' => ['[{"markdown":'];
        yield 'root is object' => ['{"markdown": "a"}'];
        yield 'root is null' => ['null'];
        yield 'element is scalar' => ['["nope"]'];
        yield 'missing field' => ['[{"markdown": "a\n", "html": "<p>a</p>\n", "example": 1, "start_line": 1, "end_line": 3}]'];
        yield 'string where int expected' => ['[{"markdown": "a\n", "html": "<p>a</p>\n", "example": "1", "start_line": 1, "end_line": 3, "section": "Alpha"}]'];
        yield 'int where string expected' => ['[{"markdown": 7, "html": "<p>a</p>\n", "example": 1, "start_line": 1, "end_line": 3, "section": "Alpha"}]'];
    }
}
