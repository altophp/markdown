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
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\InlineCache;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use PHPUnit\Framework\TestCase;

/**
 * Proves the laziness rule (SPEC section 2): reading block structure must
 * never trigger inline parsing, and reading a subset of blocks must cost
 * exactly one inline parse per block read, no more. The instrumentation
 * counters are the ledger; the CommonMark spec.txt corpus is the workload.
 */
final class LazinessTest extends TestCase
{
    /**
     * Block-parsing a large real document must not do a single inline
     * parse. If this fails, some block-phase path is calling InlineParser:
     * a real laziness violation, not a test to relax.
     */
    public function testBlockParsingDoesNoInlineWork(): void
    {
        Instrumentation::reset();

        [, $tape] = $this->parseBlocks($this->corpus());

        self::assertGreaterThan(0, $tape->count(), 'the corpus should produce blocks');
        self::assertSame(0, Instrumentation::$inlineParses, 'block parsing must not parse any inline content');
        self::assertSame(0, Instrumentation::$cacheHits, 'block parsing must not touch the inline cache');
    }

    /**
     * A heading-only read walks the block tree and parses inlines for the
     * heading blocks alone. The counter must land on exactly the heading
     * count: paragraphs, code, and list bodies stay unparsed.
     */
    public function testHeadingReadParsesOnlyHeadings(): void
    {
        Instrumentation::reset();

        [$buffer, $tape, $document, $referenceMap] = $this->parseBlocks($this->corpus());

        $ordinals = [];
        $this->collectOrdinals($tape, $document, $ordinals);
        $headings = $this->headingOrdinals($tape, $ordinals);

        self::assertNotSame([], $headings, 'the corpus should contain headings');
        self::assertSame(0, Instrumentation::$inlineParses, 'walking the tree must not parse inlines');

        $inline = new InlineParser();
        $cache = new InlineCache();

        foreach ($headings as $ordinal) {
            $this->readInline($inline, $cache, $buffer, $tape, $referenceMap, $ordinal);
        }

        self::assertSame(
            \count($headings),
            Instrumentation::$inlineParses,
            'reading N headings must cost exactly N inline parses',
        );
        self::assertSame(0, Instrumentation::$cacheHits, 'first read of each heading is a cache miss');
    }

    /**
     * Re-reading the same headings through the same cache adds no parses
     * and turns every read into a cache hit. This is the payoff of the
     * generation-keyed cache: unchanged blocks are never reparsed.
     */
    public function testRereadHitsCacheWithoutReparsing(): void
    {
        Instrumentation::reset();

        [$buffer, $tape, $document, $referenceMap] = $this->parseBlocks($this->corpus());

        $ordinals = [];
        $this->collectOrdinals($tape, $document, $ordinals);
        $headings = $this->headingOrdinals($tape, $ordinals);

        $inline = new InlineParser();
        $cache = new InlineCache();

        foreach ($headings as $ordinal) {
            $this->readInline($inline, $cache, $buffer, $tape, $referenceMap, $ordinal);
        }

        $parsesAfterFirstRead = Instrumentation::$inlineParses;
        self::assertSame(\count($headings), $parsesAfterFirstRead);

        foreach ($headings as $ordinal) {
            $this->readInline($inline, $cache, $buffer, $tape, $referenceMap, $ordinal);
        }

        self::assertSame(
            $parsesAfterFirstRead,
            Instrumentation::$inlineParses,
            're-reading cached headings must not reparse',
        );
        self::assertSame(
            \count($headings),
            Instrumentation::$cacheHits,
            're-reading N cached headings must be N cache hits',
        );
    }

    private function corpus(): string
    {
        $path = \dirname(__DIR__, 2).'/tests/fixtures/spec.txt';
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'spec.txt fixture must be readable');

        return $contents;
    }

    /**
     * Block-parses a document and returns the pieces a reader needs: the
     * source buffer, the block tape, the document root ordinal, and the
     * reference map. No inline work happens here.
     *
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
     * Reads one block's inlines through the cache, exactly as the product
     * render path does: miss parses and stores, hit returns the stored tape.
     */
    private function readInline(
        InlineParser $inline,
        InlineCache $cache,
        SourceBuffer $buffer,
        ParseTape $tape,
        ReferenceMap $referenceMap,
        int $ordinal,
    ): ParseTape {
        $generation = $tape->generation($ordinal);
        $cached = $cache->get($ordinal, $generation);

        if (null !== $cached) {
            return $cached;
        }

        $parsed = $inline->parse($buffer, $this->contentPairs($tape, $ordinal), $referenceMap);
        $cache->put($ordinal, $generation, $parsed);

        return $parsed;
    }

    /**
     * Collects every reachable block ordinal in document order by walking
     * child and sibling links from the document root down.
     *
     * @param list<int> $out
     */
    private function collectOrdinals(ParseTape $tape, int $parent, array &$out): void
    {
        $child = $tape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $child) {
            $out[] = $child;
            $this->collectOrdinals($tape, $child, $out);
            $child = $tape->nextSiblingOrdinal($child);
        }
    }

    /**
     * @param list<int> $ordinals
     *
     * @return list<int>
     */
    private function headingOrdinals(ParseTape $tape, array $ordinals): array
    {
        $headings = [];

        foreach ($ordinals as $ordinal) {
            $kind = $tape->kindId($ordinal);

            if (BlockKind::ATX_HEADING === $kind || BlockKind::SETEXT_HEADING === $kind) {
                $headings[] = $ordinal;
            }
        }

        return $headings;
    }

    /**
     * Content byte ranges from a "cs:ce[:pad][;...]" payload, falling back
     * to the block's own span when no payload was recorded. This mirrors
     * the reader wiring in TestHtmlRenderer; only the (start, end, pad)
     * triple the inline parser consumes is produced here.
     *
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
