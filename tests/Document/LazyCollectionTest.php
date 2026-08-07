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

namespace Alto\Markdown\Tests\Document;

use Alto\Markdown\Document\Query\LazyCollection;
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\TestCase;

final class LazyCollectionTest extends TestCase
{
    public function testFirstStopsAtFirstItem(): void
    {
        $visited = 0;
        $collection = new LazyCollection(static function () use (&$visited): \Generator {
            foreach ([1, 2, 3] as $value) {
                ++$visited;
                yield $value;
            }
        });

        self::assertSame(1, $collection->first());
        self::assertSame(1, $visited);
    }

    public function testFilterComposesWithoutEagerTraversal(): void
    {
        $visited = 0;
        $collection = new LazyCollection(static function () use (&$visited): \Generator {
            foreach ([1, 2, 3, 4] as $value) {
                ++$visited;
                yield $value;
            }
        });

        $filtered = $collection
            ->filter(static fn (int $value): bool => 0 === $value % 2)
            ->filter(static fn (int $value): bool => $value > 2);

        self::assertSame(0, $visited);
        self::assertSame([4], $filtered->all());
        self::assertSame(4, $visited);
    }

    public function testCountTraversesWithoutInlineParsingUnrelatedBlocks(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            Paragraph with [a link](https://example.com) and ![image](pic.png).

            ## Install
            MD);

        Instrumentation::reset();

        self::assertCount(2, $document->headings());
        self::assertSame(0, Instrumentation::$inlineParses);
    }

    public function testHeadingFilterKeepsGenericTypeAndOrder(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            ## Install

            ### Details
            MD);

        $headings = $document->headings()
            ->filter(static fn (Heading $heading): bool => $heading->level() >= 2)
            ->all();

        self::assertContainsOnlyInstancesOf(Heading::class, $headings);
        self::assertSame(['Install', 'Details'], \array_map(
            static fn (Heading $heading): string => $heading->text(),
            $headings,
        ));
    }
}
