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
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Tests\Support\MarkdownRoundTripHarness;
use PHPUnit\Framework\TestCase;

final class MarkdownRoundTripHarnessTest extends TestCase
{
    public function testSingleDocumentRoundTripUsesDefaultRenderer(): void
    {
        $source = <<<'MD'
            # Title

            Paragraph with [link](https://example.com) and `code`.

            ```php
            echo "ok";
            ```
            MD;

        $comparison = new MarkdownRoundTripHarness(Markdown::github())->compare($source);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testNamedCorpusRoundTripReportsFirstDifference(): void
    {
        $harness = new MarkdownRoundTripHarness(Markdown::github());

        $comparison = $harness->compareCorpus(
            [
                'bad-heading' => "# Title\n",
            ],
            static fn(MarkdownDocument $document, string $name): string => "Not a heading\n",
        );

        self::assertFalse($comparison->isEqual());
        self::assertStringContainsString('Round-trip case "bad-heading" failed', $comparison->message());
        self::assertStringContainsString('kind differs', $comparison->message());
    }

    public function testNamedCorpusRoundTripPassesMultipleCases(): void
    {
        $comparison = new MarkdownRoundTripHarness(Markdown::github())->compareCorpus([
            'heading' => "# Title\n",
            'paragraph' => "Paragraph with *emphasis*.\n",
            'code' => "```php\necho \"ok\";\n```\n",
        ]);

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }
}
