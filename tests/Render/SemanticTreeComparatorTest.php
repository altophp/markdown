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

namespace Alto\Markdown\Tests\Render;

use Alto\Markdown\Markdown;
use Alto\Markdown\Tests\Support\SemanticTreeComparator;
use PHPUnit\Framework\TestCase;

final class SemanticTreeComparatorTest extends TestCase
{
    public function testEquivalentTreesCompareEqual(): void
    {
        $left = Markdown::github()->fromString("# Title\n\nParagraph with [link](https://example.com).\n");
        $right = Markdown::github()->fromString("# Title\n\nParagraph with [link](https://example.com).\n");

        $comparison = new SemanticTreeComparator()->compare($left->model(), $right->model());

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testSourceOffsetsAreIgnored(): void
    {
        $left = Markdown::github()->fromString("# Title\n\nParagraph with `code`.\n");
        $right = Markdown::github()->fromString("\n\n# Title\n\nParagraph with `code`.\n");

        $comparison = new SemanticTreeComparator()->compare($left->model(), $right->model());

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testDifferentBlockKindIsReported(): void
    {
        $left = Markdown::github()->fromString("# Title\n");
        $right = Markdown::github()->fromString("Title\n");

        $comparison = new SemanticTreeComparator()->compare($left->model(), $right->model());

        self::assertFalse($comparison->isEqual());
        self::assertStringContainsString('kind differs', $comparison->message());
    }

    public function testDifferentBlockPayloadIsReported(): void
    {
        $left = Markdown::github()->fromString("# One\n");
        $right = Markdown::github()->fromString("# Two\n");

        $comparison = new SemanticTreeComparator()->compare($left->model(), $right->model());

        self::assertFalse($comparison->isEqual());
        self::assertStringContainsString('payload differs', $comparison->message());
    }

    public function testIndentedAndFencedCodeCompareByCodePayload(): void
    {
        $left = Markdown::github()->fromString("    echo \"ok\";\n");
        $right = Markdown::github()->fromString("```\necho \"ok\";\n```\n");

        $comparison = new SemanticTreeComparator()->compare($left->model(), $right->model());

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testDifferentListPayloadIsReported(): void
    {
        $left = Markdown::github()->fromString("3. three\n");
        $right = Markdown::github()->fromString("1. three\n");

        $comparison = new SemanticTreeComparator()->compare($left->model(), $right->model());

        self::assertFalse($comparison->isEqual());
        self::assertStringContainsString('payload differs', $comparison->message());
    }

    public function testDifferentInlinePayloadIsReported(): void
    {
        $left = Markdown::github()->fromString("[site](https://a.example)\n");
        $right = Markdown::github()->fromString("[site](https://b.example)\n");

        $comparison = new SemanticTreeComparator()->compare($left->model(), $right->model());

        self::assertFalse($comparison->isEqual());
        self::assertStringContainsString('inline payloads differ', $comparison->message());
    }

    public function testDifferentChildOrderIsReported(): void
    {
        $left = Markdown::github()->fromString("# One\n\n# Two\n");
        $right = Markdown::github()->fromString("# Two\n\n# One\n");

        $comparison = new SemanticTreeComparator()->compare($left->model(), $right->model());

        self::assertFalse($comparison->isEqual());
        self::assertStringContainsString('/document[0]', $comparison->message());
    }
}
