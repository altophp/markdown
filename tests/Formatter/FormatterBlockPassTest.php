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

namespace Alto\Markdown\Tests\Formatter;

use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Tests\Support\SemanticTreeComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatterBlockPassTest extends TestCase
{
    public function testAtxHeadingSpacingHasExpectedOutputNoOpAndIdempotence(): void
    {
        $source = "#  Title\n\n> ##\tNested\n";
        $expected = "# Title\n\n> ## Nested\n";

        $first = $this->format($source);
        $second = $this->format($first['bytes']);

        self::assertSame($expected, $first['bytes']);
        self::assertSame(['format ATX heading spacing', 'format ATX heading spacing'], $first['operations']);
        self::assertSame($first['bytes'], $second['bytes']);
        self::assertSame([], $second['operations']);
        self::assertSame([], $this->format("# Title\n\n> ## Nested\n")['operations']);
    }

    public function testFenceMarkerPreferenceHasExpectedOutputNoOpAndIdempotence(): void
    {
        $source = "~~~php\necho \"```\";\n~~~\n";
        $expected = "````php\necho \"```\";\n````\n";

        $first = $this->format($source);
        $second = $this->format($first['bytes']);

        self::assertSame($expected, $first['bytes']);
        self::assertSame(['format opening fence marker', 'format closing fence marker'], $first['operations']);
        self::assertSame($first['bytes'], $second['bytes']);
        self::assertSame([], $second['operations']);
        self::assertSame([], $this->format("```php\necho 'ok';\n```\n")['operations']);
    }

    public function testFenceInfoSpacingMatchesTheLintFixAndPreservesPayload(): void
    {
        $source = "``` \t php title=value\r\ncode  \r\n```\r\n";
        $expected = "```php title=value\r\ncode  \r\n```\r\n";

        $first = $this->format($source);
        $second = $this->format($first['bytes']);
        $lintFixed = Markdown::github()->fromString($source);
        $lintFixed->fix((new LintConfig())->withRule('code-fence-info-spacing'));

        self::assertSame($expected, $first['bytes']);
        self::assertSame($first['bytes'], $lintFixed->toMarkdown());
        self::assertSame(['format code fence info spacing'], $first['operations']);
        self::assertSame($first['bytes'], $second['bytes']);
        self::assertSame([], $second['operations']);
    }

    public function testFenceMarkerAndInfoSpacingComposeAsAdjacentPatches(): void
    {
        $formatted = $this->format("~~~  php\ncode\n~~~\n");

        self::assertSame("```php\ncode\n```\n", $formatted['bytes']);
        self::assertSame([
            'format opening fence marker',
            'format closing fence marker',
            'format code fence info spacing',
        ], $formatted['operations']);
        self::assertSame([], $formatted['fallbacks']);
    }

    public function testFenceMarkerFindsAnIndentedClosingFence(): void
    {
        $formatted = $this->format("~~~\ncode\n   ~~~\n");

        self::assertSame("```\ncode\n   ```\n", $formatted['bytes']);
        self::assertSame([
            'format opening fence marker',
            'format closing fence marker',
        ], $formatted['operations']);
    }

    public function testBulletMarkerPreferenceHasExpectedOutputNoOpAndIdempotence(): void
    {
        $source = "+ one\n  * nested\n+ three\n";
        $expected = "- one\n  - nested\n- three\n";

        $first = $this->format($source);
        $second = $this->format($first['bytes']);

        self::assertSame($expected, $first['bytes']);
        self::assertSame(['format bullet marker', 'format bullet marker', 'format bullet marker'], $first['operations']);
        self::assertSame($first['bytes'], $second['bytes']);
        self::assertSame([], $second['operations']);
        self::assertSame([], $this->format("- one\n  - nested\n")['operations']);
    }

    public function testBulletMarkerPreferenceDoesNotMergeDistinctLists(): void
    {
        $source = "- one\n- two\n+ three\n";

        self::assertSame($source, $this->format($source)['bytes']);
    }

    public function testOrderedListDelimiterHasExpectedOutputNoOpAndIdempotence(): void
    {
        $source = "1) one\n2) two\n   1) nested\n";
        $expected = "1. one\n2. two\n   1. nested\n";

        $first = $this->format($source);
        $second = $this->format($first['bytes']);

        self::assertSame($expected, $first['bytes']);
        self::assertSame([
            'format ordered-list delimiter',
            'format ordered-list delimiter',
            'format ordered-list delimiter',
        ], $first['operations']);
        self::assertSame($first['bytes'], $second['bytes']);
        self::assertSame([], $second['operations']);
        $this->assertSemanticsPreserved($source, $first['bytes']);
    }

    public function testOrderedListDelimiterDoesNotMergeDistinctLists(): void
    {
        $source = "1. one\n2. two\n1) three\n";

        self::assertSame($source, $this->format($source)['bytes']);
    }

    public function testOrderedListDelimiterCanPreferClosingParenthesis(): void
    {
        $source = "3. three\n4. four\n";
        $style = new MarkdownStyle(orderedListDelimiter: ')');
        $formatted = $this->format($source, $style)['bytes'];

        self::assertSame("3) three\n4) four\n", $formatted);
        $this->assertSemanticsPreserved($source, $formatted);
    }

    public function testOrderedListDelimiterSupportsLineEndingsAndContainers(): void
    {
        $source = "> 1) quote\r\n>    1) nested\r\n\r1) cr\r";
        $expected = "> 1. quote\r\n>    1. nested\r\n\r1. cr\r";
        $formatted = $this->format($source)['bytes'];

        self::assertSame($expected, $formatted);
        $this->assertSemanticsPreserved($source, $formatted);
    }

    public function testListMarkerFormattingHandlesManyAdjacentLists(): void
    {
        $source = implode('', array_map(
            static fn(int $line): string => 0 === $line % 2 ? "1. item {$line}\n" : "1) item {$line}\n",
            range(1, 2_000),
        ));
        $started = hrtime(true);
        $formatted = $this->format($source)['bytes'];
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertSame($source, $formatted);
        self::assertLessThan(1.0, $elapsed, 'List formatting must stay near-linear across many adjacent lists.');
    }

    public function testTableDelimiterFormattingIsSemanticAndIdempotent(): void
    {
        $source = "| Left | Center | Right | None |\r\n|:------|:-----:|------:|------|\r\n| a | b | c | d |\r\n";
        $expected = "| Left | Center | Right | None |\r\n| :--- | :---: | ---: | --- |\r\n| a | b | c | d |\r\n";

        $first = $this->format($source);
        $second = $this->format($first['bytes']);

        self::assertSame($expected, $first['bytes']);
        self::assertSame(['format table delimiter row'], $first['operations']);
        self::assertSame($first['bytes'], $second['bytes']);
        self::assertSame([], $second['operations']);
        $this->assertSemanticsPreserved($source, $first['bytes']);
    }

    public function testTableDelimiterFormattingPreservesOuterPipeChoiceAndIndentation(): void
    {
        $source = "A | B\n  :-----|-----:\nX | Y\n";
        $formatted = $this->format($source)['bytes'];

        self::assertSame("A | B\n  :--- | ---:\nX | Y\n", $formatted);
        $this->assertSemanticsPreserved($source, $formatted);
    }

    public function testTableDelimiterFormattingComposesWithTrailingWhitespaceRemoval(): void
    {
        $source = "| A | B |\n|:-----|-----:|   \n| x | y |\n";
        $expected = "| A | B |\n| :--- | ---: |\n| x | y |\n";

        $first = $this->format($source);
        $second = $this->format($first['bytes']);

        self::assertSame($expected, $first['bytes']);
        self::assertSame([
            'format trailing spaces',
            'format table delimiter row',
        ], $first['operations']);
        self::assertSame([], $first['fallbacks']);
        self::assertSame([], $second['operations']);
        $this->assertSemanticsPreserved($source, $first['bytes']);
    }

    public function testTableDelimiterFormattingSkipsNestedTablesAndCanBeDisabled(): void
    {
        $nested = "> | A |\n> |:------|\n> | X |\n";
        $topLevel = "| A |\n|:------|\n| X |\n";

        self::assertSame($nested, $this->format($nested)['bytes']);
        self::assertSame($topLevel, $this->format(
            $topLevel,
            new MarkdownStyle(normalizeTableDelimiters: false),
        )['bytes']);
    }

    public function testReferenceDefinitionSpacingIsSemanticAndIdempotent(): void
    {
        $source = "[foo]:\t\t<https://example.com> \"Title\"\r\n[bar]:/path\r\n\r\n[foo] [bar]\r\n";
        $expected = "[foo]: <https://example.com> \"Title\"\r\n[bar]: /path\r\n\r\n[foo] [bar]\r\n";

        $first = $this->format($source);
        $second = $this->format($first['bytes']);

        self::assertSame($expected, $first['bytes']);
        self::assertSame([
            'format reference definition spacing',
            'format reference definition spacing',
        ], $first['operations']);
        self::assertSame($first['bytes'], $second['bytes']);
        self::assertSame([], $second['operations']);
        $this->assertSemanticsPreserved($source, $first['bytes']);
    }

    public function testReferenceDefinitionSpacingSkipsMultilineDefinitionsAndCanBeDisabled(): void
    {
        $multiline = "[foo]:\n  /path\n";
        $singleLine = "[foo]:\t/path\n";

        self::assertSame($multiline, $this->format($multiline)['bytes']);
        self::assertSame($singleLine, $this->format(
            $singleLine,
            new MarkdownStyle(normalizeReferenceDefinitionSpacing: false),
        )['bytes']);
    }

    public function testTableAndReferenceFormattingSupportCrOnlyContainers(): void
    {
        $source = "| A |\r|:------|\r| x |\r\r> [foo]:\t/path\r>\r> [foo]\r";
        $expected = "| A |\r| :--- |\r| x |\r\r> [foo]: /path\r>\r> [foo]\r";
        $formatted = $this->format($source)['bytes'];

        self::assertSame($expected, $formatted);
        $this->assertSemanticsPreserved($source, $formatted);
    }

    #[DataProvider('distinctListBoundaryProvider')]
    public function testBulletMarkerPreferencePreservesDistinctListBoundaries(string $source): void
    {
        $formatted = $this->format($source)['bytes'];
        $originalDocument = Markdown::github()->fromString($source);
        $formattedDocument = Markdown::github()->fromString($formatted);
        $comparison = new SemanticTreeComparator()->compare($originalDocument->model(), $formattedDocument->model());

        self::assertSame($source, $formatted);
        self::assertTrue($comparison->isEqual(), $comparison->message());
        self::assertSame($originalDocument->toHtml(), $formattedDocument->toHtml());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function distinctListBoundaryProvider(): iterable
    {
        yield 'blank line between top-level lists' => ["- a\n\n+ b\n"];
        yield 'indented adjacent list blocks' => ["  - a\n  + b\n"];
        yield 'CR-only indented adjacent list blocks' => ["  - a\r  + b\r"];
    }

    #[DataProvider('thematicBreakRiskProvider')]
    public function testBulletMarkerPreferenceDoesNotCreateThematicBreak(string $source, string $expected): void
    {
        self::assertSame($expected, $this->format($source)['bytes']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function thematicBreakRiskProvider(): iterable
    {
        yield 'nested dash plus dash' => ["- + -\n\t*", "- + -\n\t-\n"];
        yield 'nested stars and plus' => ["* * +\n\ta@b.com>   ", "* * +\n\ta@b.com>  \n"];
        yield 'nested star dash plus' => ["* - +\n\t_", "* - +\n\t_\n"];
    }

    public function testBlankLineNormalizationHasExpectedOutputNoOpAndIdempotence(): void
    {
        $source = "# Title\r\n\r\n \t\r\n\r\nParagraph\r\n\r\n- item\r\n";
        $expected = "# Title\r\n\r\nParagraph\r\n\r\n- item\r\n";

        $first = $this->format($source);
        $second = $this->format($first['bytes']);

        self::assertSame($expected, $first['bytes']);
        self::assertContains('normalize blank lines between top-level blocks', $first['operations']);
        self::assertSame([], $first['fallbacks']);
        self::assertSame($first['bytes'], $second['bytes']);
        self::assertSame([], $second['operations']);
        self::assertSame([], $this->format("# Title\n\nParagraph\n")['operations']);
    }

    public function testBlankLineNormalizationSupportsCrOnlyDocuments(): void
    {
        $source = "# Title\r\r\r\rParagraph\r";

        self::assertSame("# Title\r\rParagraph\r", $this->format($source)['bytes']);
    }

    public function testStyleCanKeepExistingBlockMarkersAndSpacing(): void
    {
        $source = "#  Title\n\n\n+ item\n\n~~~\nx\n~~~\n";
        $style = new MarkdownStyle(
            bulletMarker: '+',
            fenceMarker: '~',
            normalizeBlankLines: false,
            normalizeHeadingSpacing: false,
        );

        self::assertSame([], $this->format($source, $style)['operations']);
    }

    /**
     * @return array{bytes: string, operations: list<string>, fallbacks: list<string>}
     */
    private function format(string $source, ?MarkdownStyle $style = null): array
    {
        $document = Markdown::github()->fromString($source);
        $document->format($style);
        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());
        $operations = array_map(
            static fn($entry): string => $entry->operation->describe(),
            $document->model()->journal()->entries(),
        );

        return [
            'bytes' => $result->bytes,
            'operations' => $operations,
            'fallbacks' => array_map(static fn($fallback): string => $fallback->reason, $result->fallbacks),
        ];
    }

    private function assertSemanticsPreserved(string $source, string $formatted): void
    {
        $originalDocument = Markdown::github()->fromString($source);
        $formattedDocument = Markdown::github()->fromString($formatted);
        $comparison = new SemanticTreeComparator()->compare($originalDocument->model(), $formattedDocument->model());

        self::assertTrue($comparison->isEqual(), $comparison->message());
        self::assertSame($originalDocument->toHtml(), $formattedDocument->toHtml());
    }
}
