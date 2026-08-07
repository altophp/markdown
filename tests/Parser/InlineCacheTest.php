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

use Alto\Markdown\Parser\InlineCache;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\TestCase;

final class InlineCacheTest extends TestCase
{
    public function testMissThenHit(): void
    {
        Instrumentation::reset();
        $cache = new InlineCache();
        $tape = new ParseTape();

        self::assertNull($cache->get(3, 0));

        $cache->put(3, 0, $tape);

        self::assertSame($tape, $cache->get(3, 0));
        self::assertSame(1, Instrumentation::$cacheHits);
    }

    public function testGenerationBumpEvicts(): void
    {
        $cache = new InlineCache();
        $cache->put(3, 0, new ParseTape());

        self::assertNull($cache->get(3, 1));
        self::assertNull($cache->get(3, 0));
    }
}
