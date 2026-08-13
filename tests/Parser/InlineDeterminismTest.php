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

use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use PHPUnit\Framework\TestCase;

/**
 * Proves inline determinism (SPEC section 2): a block's inline tape depends
 * only on that block's content, never on when or in what order the block is
 * read. Inline ordinals are per-block tape indices, so parsing block B before
 * block A cannot change either block's tape. The TapeDumper snapshot is the
 * witness: byte-identical dumps mean byte-identical trees.
 */
final class InlineDeterminismTest extends TestCase
{
    private const string FIXTURE = "# Title with *emphasis* and `code`\n\n"
        . "A paragraph with **strong** text, a [link](https://example.com \"ref\") plus a `code span`, an entity &amp; and a numeric &#42; ref.\n\n"
        . "Second paragraph adds _italic_, an ![image](/pic.png \"pic\"), and an autolink <https://auto.example.com>.\n";

    /**
     * Parsing the same block content twice into fresh tapes yields a
     * byte-identical dump. No hidden state, no ordering, no time dependence.
     */
    public function testInlineParseIsDeterministicPerBlock(): void
    {
        [$buffer, $tape, $document, $referenceMap] = $this->parseBlocks(self::FIXTURE);
        $ordinals = $this->inlineBearingOrdinals($tape, $document);
        self::assertGreaterThanOrEqual(2, \count($ordinals), 'the fixture should expose several inline-bearing blocks');

        $dumper = new TapeDumper();

        foreach ($ordinals as $ordinal) {
            $pairs = $this->contentPairs($tape, $ordinal);
            $first = new InlineParser()->parse($buffer, $pairs, $referenceMap);
            $second = new InlineParser()->parse($buffer, $pairs, $referenceMap);

            $firstDump = $dumper->dump($first);

            self::assertNotSame('', $firstDump, \sprintf('block %d should produce inline nodes', $ordinal));
            self::assertSame(
                $firstDump,
                $dumper->dump($second),
                \sprintf('block %d inline dump must be byte-identical across parses', $ordinal),
            );
        }
    }

    /**
     * Reading blocks in reverse order produces the same per-block dumps as
     * reading them forward, using one shared parser instance. Order and a
     * reused parser leak no state between blocks.
     */
    public function testInlineParseIsOrderIndependent(): void
    {
        [$buffer, $tape, $document, $referenceMap] = $this->parseBlocks(self::FIXTURE);
        $ordinals = $this->inlineBearingOrdinals($tape, $document);
        self::assertGreaterThanOrEqual(2, \count($ordinals));

        $inline = new InlineParser();
        $dumper = new TapeDumper();

        $forward = [];

        foreach ($ordinals as $ordinal) {
            $forward[$ordinal] = $dumper->dump($inline->parse($buffer, $this->contentPairs($tape, $ordinal), $referenceMap));
        }

        $reverse = [];

        foreach (array_reverse($ordinals) as $ordinal) {
            $reverse[$ordinal] = $dumper->dump($inline->parse($buffer, $this->contentPairs($tape, $ordinal), $referenceMap));
        }

        foreach ($ordinals as $ordinal) {
            self::assertSame(
                $forward[$ordinal],
                $reverse[$ordinal],
                \sprintf('block %d dump must not depend on parse order', $ordinal),
            );
        }
    }

    /**
     * Guards the fixture itself: the determinism claims are only meaningful
     * if the content actually exercises the rich inline constructs. Asserts
     * emphasis, strong, links, images, code spans, and autolinks all appear,
     * and that at least one text run carries a resolved payload (an entity or
     * escape, not a raw slice).
     */
    public function testFixtureExercisesRichInlines(): void
    {
        [$buffer, $tape, $document, $referenceMap] = $this->parseBlocks(self::FIXTURE);
        $ordinals = $this->inlineBearingOrdinals($tape, $document);

        $inline = new InlineParser();
        $kinds = [];
        $resolvedText = false;

        foreach ($ordinals as $ordinal) {
            $inlineTape = $inline->parse($buffer, $this->contentPairs($tape, $ordinal), $referenceMap);

            for ($node = 0; $node < $inlineTape->count(); ++$node) {
                $kind = $inlineTape->kindId($node);
                $kinds[$kind] = true;

                if (InlineKind::TEXT === $kind && null !== $inlineTape->payload($node)) {
                    $resolvedText = true;
                }
            }
        }

        foreach ([
            InlineKind::EMPHASIS,
            InlineKind::STRONG,
            InlineKind::LINK,
            InlineKind::IMAGE,
            InlineKind::CODE_SPAN,
            InlineKind::AUTOLINK,
        ] as $required) {
            self::assertArrayHasKey($required, $kinds, \sprintf('fixture must exercise inline kind %d', $required));
        }

        self::assertTrue($resolvedText, 'fixture must exercise entity or escape resolution (a resolved TEXT payload)');
    }

    /**
     * @return array{SourceBuffer, ParseTape, int, ReferenceMap}
     */
    private function parseBlocks(string $markdown): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $map, $tape);

        $blockParser = new BlockParser();
        $document = $blockParser->parse($state);

        return [$buffer, $tape, $document, $blockParser->referenceMap()];
    }

    /**
     * The reachable leaf blocks that carry inline content, in document order.
     *
     * @return list<int>
     */
    private function inlineBearingOrdinals(ParseTape $tape, int $document): array
    {
        $ordinals = [];
        $this->collect($tape, $document, $ordinals);
        $leaves = [];

        foreach ($ordinals as $ordinal) {
            $kind = $tape->kindId($ordinal);

            if (BlockKind::PARAGRAPH === $kind || BlockKind::ATX_HEADING === $kind || BlockKind::SETEXT_HEADING === $kind) {
                $leaves[] = $ordinal;
            }
        }

        return $leaves;
    }

    /**
     * @param list<int> $out
     */
    private function collect(ParseTape $tape, int $parent, array &$out): void
    {
        $child = $tape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $child) {
            $out[] = $child;
            $this->collect($tape, $child, $out);
            $child = $tape->nextSiblingOrdinal($child);
        }
    }

    /**
     * @return list<array{int, int, int}>
     */
    private function contentPairs(ParseTape $tape, int $ordinal): array
    {
        $payload = $tape->payload($ordinal);

        if (null === $payload || '' === $payload) {
            return [[$tape->startOffset($ordinal), $tape->endOffset($ordinal), 0]];
        }

        $pairs = [];

        foreach (explode(';', $payload) as $pair) {
            $parts = explode(':', $pair, 4);
            $pairs[] = [(int) $parts[0], (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0)];
        }

        return $pairs;
    }
}
