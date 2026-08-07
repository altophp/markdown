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

namespace Alto\Markdown\Tests\Operation;

use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\Diff;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use PHPUnit\Framework\TestCase;

final class DiffTest extends TestCase
{
    public function testUnifiedStringAndHunkRanges(): void
    {
        $diff = Diff::between("a\nb\nc\n", "a\nB\nc\n", 'before.md', 'after.md');

        self::assertFalse($diff->isEmpty());
        self::assertSame(<<<'DIFF'
            --- before.md
            +++ after.md
            @@ -1,3 +1,3 @@
             a
            -b
            +B
             c

            DIFF, $diff->toUnifiedString());
        self::assertCount(1, $diff->hunks());
        self::assertSame(1, $diff->hunks()[0]->originalStartLine);
        self::assertSame(3, $diff->hunks()[0]->originalLineCount);
        self::assertSame(1, $diff->hunks()[0]->editedStartLine);
        self::assertSame(3, $diff->hunks()[0]->editedLineCount);
    }

    public function testSeparatedChangesCreateSeparateHunksWhenContextIsZero(): void
    {
        $diff = Diff::between("a\nb\nc\nd\n", "A\nb\nc\nD\n", context: 0);

        self::assertCount(2, $diff->hunks());
        self::assertSame(1, $diff->hunks()[0]->originalStartLine);
        self::assertSame(1, $diff->hunks()[0]->originalLineCount);
        self::assertSame(4, $diff->hunks()[1]->originalStartLine);
        self::assertSame(1, $diff->hunks()[1]->originalLineCount);
    }

    public function testEmptyDiffHasNoHunks(): void
    {
        $diff = Diff::between("same\n", "same\n");

        self::assertTrue($diff->isEmpty());
        self::assertSame('', $diff->toUnifiedString());
        self::assertSame([], $diff->hunks());
    }

    public function testDiffSupportsInsertionIntoAndDeletionToEmptyFiles(): void
    {
        $insertion = Diff::between('', "added\n");
        $deletion = Diff::between("removed\n", '');

        self::assertStringContainsString("+added\n", $insertion->toUnifiedString());
        self::assertStringContainsString("-removed\n", $deletion->toUnifiedString());
        self::assertSame(0, $insertion->hunks()[0]->originalLineCount);
        self::assertSame(0, $deletion->hunks()[0]->editedLineCount);
    }

    public function testPreviewDiffIsStableAndMatchesLoweredBytes(): void
    {
        $source = "```php\necho \"old\";\n```\n";
        $document = Markdown::github()->fromString($source);
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("echo \"new\";\n");

        $first = $document->diff();
        $second = $document->diff();
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame($first->toUnifiedString(), $second->toUnifiedString());
        self::assertSame(Diff::between($source, $lowered->bytes)->toUnifiedString(), $first->toUnifiedString());
        self::assertFalse($document->model()->journal()->isEmpty());
    }

    public function testLargeAlignedDiffStaysBoundedAndRepresentsEveryReplacement(): void
    {
        $originalLines = array_map(static fn (int $line): string => "line {$line}\n", range(1, 1_100));
        $editedLines = $originalLines;
        $editedLines[99] = "changed 100\n";
        $editedLines[999] = "changed 1000\n";
        $original = implode('', $originalLines);
        $edited = implode('', $editedLines);

        $diff = Diff::between($original, $edited, context: 1);

        self::assertCount(2, $diff->hunks());
        self::assertSame($edited, $this->apply($original, $diff));
    }

    public function testLargeUnequalDiffKeepsCommonEdgesAndRepresentsTheInsertion(): void
    {
        $originalLines = array_map(static fn (int $line): string => "line {$line}\n", range(1, 1_100));
        $editedLines = $originalLines;
        array_splice($editedLines, 550, 0, ["inserted\n"]);
        $original = implode('', $originalLines);
        $edited = implode('', $editedLines);

        $diff = Diff::between($original, $edited, context: 1);

        self::assertCount(1, $diff->hunks());
        self::assertSame($edited, $this->apply($original, $diff));
    }

    public function testLargeEqualLengthShiftUsesStableAnchors(): void
    {
        $originalLines = array_map(static fn (int $line): string => "line {$line}\n", range(1, 1_100));
        $editedLines = $originalLines;
        array_splice($editedLines, 100, 0, ["inserted\n"]);
        array_splice($editedLines, 1_000, 1);
        $original = implode('', $originalLines);
        $edited = implode('', $editedLines);

        $diff = Diff::between($original, $edited, context: 1);

        self::assertCount(2, $diff->hunks());
        self::assertLessThanOrEqual(10, substr_count($diff->toUnifiedString(), "\n"));
        self::assertSame($edited, $this->apply($original, $diff));
    }

    public function testLargeRepeatedInputsUseAlignedFallback(): void
    {
        $originalLines = array_fill(0, 1_100, "same\n");
        $editedLines = $originalLines;
        $editedLines[0] = "changed\n";
        $editedLines[1_099] = "changed\n";
        $original = implode('', $originalLines);
        $edited = implode('', $editedLines);

        $diff = Diff::between($original, $edited, context: 1);

        self::assertCount(2, $diff->hunks());
        self::assertSame($edited, $this->apply($original, $diff));
    }

    public function testLargeUnrelatedUnequalInputsUseBoundedReplacement(): void
    {
        $original = str_repeat("original\n", 1_100);
        $edited = str_repeat("edited\n", 1_101);
        $diff = Diff::between($original, $edited, context: 1);

        self::assertCount(1, $diff->hunks());
        self::assertSame($edited, $this->apply($original, $diff));
    }

    public function testLargeReorderedInputsBuildAStableAnchorChain(): void
    {
        $originalLines = array_map(static fn (int $line): string => "line {$line}\n", range(1, 1_100));
        $editedLines = \array_reverse($originalLines);
        $original = implode('', $originalLines);
        $edited = implode('', $editedLines);
        $diff = Diff::between($original, $edited, context: 1);

        self::assertSame($edited, $this->apply($original, $diff));
    }

    private function apply(string $original, Diff $diff): string
    {
        $originalLines = '' === $original ? [] : explode("\n", rtrim($original, "\n"));
        $editedLines = [];
        $cursor = 0;

        foreach ($diff->hunks() as $hunk) {
            $start = $hunk->originalStartLine - 1;

            while ($cursor < $start) {
                $editedLines[] = $originalLines[$cursor];
                ++$cursor;
            }

            foreach ($hunk->lines as $line) {
                $type = $line[0];
                $content = substr($line, 1);

                if ('+' === $type) {
                    $editedLines[] = $content;

                    continue;
                }

                self::assertSame($originalLines[$cursor], $content);

                if (' ' === $type) {
                    $editedLines[] = $content;
                }

                ++$cursor;
            }
        }

        array_push($editedLines, ...array_slice($originalLines, $cursor));

        return implode("\n", $editedLines)."\n";
    }
}
