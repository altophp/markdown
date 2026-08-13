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

namespace Alto\Markdown\Tests\Parser;

use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\InlineCountBudget;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use PHPUnit\Framework\TestCase;

final class InlineParserTest extends TestCase
{
    /**
     * @param list<array{int, int, int}> $pairs
     *
     * @return list<array{int, string}>
     */
    private static function nodes(string $source, array $pairs): array
    {
        $buffer = new SourceBuffer($source);
        $tape = new InlineParser()->parse($buffer, $pairs, new ReferenceMap());
        $out = [];
        $node = $tape->firstChildOrdinal(0);

        while (ParseTape::NONE !== $node) {
            $out[] = [$tape->kindId($node), $buffer->substring($tape->startOffset($node), $tape->endOffset($node))];
            $node = $tape->nextSiblingOrdinal($node);
        }

        return $out;
    }

    public function testLiteralLineIsOneTextRun(): void
    {
        $nodes = self::nodes("plain text here\n", [[0, 15, 0]]);

        self::assertSame([[InlineKind::TEXT, 'plain text here']], $nodes);
    }

    public function testUnclaimedSpecialsStayInOneRun(): void
    {
        // All constructs are inert stubs today: specials join the text.
        $nodes = self::nodes("a`b&c<d\\e\n", [[0, 9, 0]]);

        self::assertSame([[InlineKind::TEXT, 'a`b&c<d\\e']], $nodes);
    }

    public function testSoftBreakBetweenLines(): void
    {
        $nodes = self::nodes("aaa\nbbb\n", [[0, 3, 0], [4, 7, 0]]);

        self::assertSame(InlineKind::TEXT, $nodes[0][0]);
        self::assertSame(InlineKind::SOFT_BREAK, $nodes[1][0]);
        self::assertSame([InlineKind::TEXT, 'bbb'], $nodes[2]);
    }

    public function testTwoTrailingSpacesMakeAHardBreak(): void
    {
        $nodes = self::nodes("aaa  \nbbb\n", [[0, 5, 0], [6, 9, 0]]);

        self::assertSame([InlineKind::TEXT, 'aaa'], $nodes[0]);
        self::assertSame(InlineKind::HARD_BREAK, $nodes[1][0]);
    }

    public function testTrailingTabBeforeSoftBreakIsPreserved(): void
    {
        // Only spaces are stripped at a line ending (spec 6.9); a trailing
        // tab is literal content and stays in the text run.
        $nodes = self::nodes("x\t\nb\n", [[0, 2, 0], [3, 4, 0]]);

        self::assertSame([InlineKind::TEXT, "x\t"], $nodes[0]);
        self::assertSame(InlineKind::SOFT_BREAK, $nodes[1][0]);
        self::assertSame([InlineKind::TEXT, 'b'], $nodes[2]);
    }

    public function testLastLineTrailingSpacesAreNotABreak(): void
    {
        $nodes = self::nodes("aaa  \n", [[0, 5, 0]]);

        self::assertSame([[InlineKind::TEXT, 'aaa']], $nodes);
    }

    public function testLinesAreTrimmedOfSurroundingWhitespace(): void
    {
        $nodes = self::nodes("  aaa\t\n", [[0, 6, 0]]);

        self::assertSame([[InlineKind::TEXT, 'aaa']], $nodes);
    }

    public function testParseIncrementsInstrumentationCounter(): void
    {
        Instrumentation::reset();

        self::nodes("aaa\n", [[0, 3, 0]]);
        self::nodes("bbb\n", [[0, 3, 0]]);

        self::assertSame(2, Instrumentation::$inlineParses);
    }

    public function testEmptyPairListProducesOnlyTheRootAndCommitsTheBudget(): void
    {
        $tape = new InlineParser()->parse(
            new SourceBuffer(''),
            [],
            new ReferenceMap(),
            new InlineCountBudget(1),
        );

        self::assertSame(1, $tape->count());
        self::assertSame(InlineKind::ROOT, $tape->kindId(0));
    }

    public function testTimedTapeParseBalancesAllInlineStages(): void
    {
        Instrumentation::measure();

        try {
            self::nodes("[*label*](/target)\n", [[0, 18, 0]]);
        } finally {
            Instrumentation::disable();
        }

        foreach (['inline-build', 'inline-scan', 'link-resolve', 'emphasis'] as $stage) {
            self::assertGreaterThan(0, Instrumentation::$stageEnters[$stage] ?? 0);
        }
        self::assertSame(0, Instrumentation::regionDepth());
    }

    public function testBudgetCommitAndEmptyTimingLeaveAreIdempotent(): void
    {
        $budget = new InlineCountBudget(2);
        $first = $budget->begin();
        $first->add(0);
        $first->commit();
        $first->commit();
        $second = $budget->begin();
        $second->add(1);
        $second->commit();

        Instrumentation::reset();
        Instrumentation::leave('not-entered');

        self::assertSame(0, Instrumentation::regionDepth());
    }

    public function testSourceOffsetsAdvanceMonotonicallyAcrossSegments(): void
    {
        $lines = array_fill(0, 100, '*value* and [link](/target)');
        $source = implode("\n", $lines) . "\n";
        $pairs = [];
        $offset = 0;

        foreach ($lines as $line) {
            $pairs[] = [$offset, $offset + \strlen($line), 0];
            $offset += \strlen($line) + 1;
        }

        Instrumentation::reset();
        self::nodes($source, $pairs);

        self::assertSame(1, Instrumentation::$inlineContentBuilds);
        self::assertSame(99, Instrumentation::$inlineOffsetSegmentAdvances);
        self::assertSame(0, Instrumentation::$inlineOffsetFallbackSearches);
    }
}
