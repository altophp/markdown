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

use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ReferenceMap;
use PHPUnit\Framework\TestCase;

final class EmphasisComplexityTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::$trackInlineComplexity = false;
    }

    public function testUnmatchedClosersHaveLinearSearchWork(): void
    {
        $previousSteps = 0;

        foreach ([1000, 2000, 4000, 8000] as $size) {
            $steps = $this->parseAndMeasure(str_repeat('a* ', $size));

            self::assertLessThanOrEqual($size, $steps['search']);
            self::assertSame(0, $steps['siblings']);

            if ($previousSteps > 0) {
                self::assertLessThanOrEqual($previousSteps * 2 + 2, $steps['search']);
            }

            $previousSteps = $steps['search'];
        }
    }

    public function testIndependentMatchesHaveLinearSiblingWork(): void
    {
        $size = 4000;
        $steps = $this->parseAndMeasure(str_repeat('*x* ', $size));

        self::assertLessThanOrEqual($size, $steps['search']);
        self::assertLessThanOrEqual($size, $steps['siblings']);
    }

    /**
     * @return array{search: int, siblings: int}
     */
    private function parseAndMeasure(string $markdown): array
    {
        Instrumentation::reset();
        Instrumentation::$trackInlineComplexity = true;
        $buffer = new SourceBuffer($markdown);
        new InlineParser()->parse($buffer, [[0, \strlen($markdown), 0]], new ReferenceMap());

        return [
            'search' => Instrumentation::$delimiterSearchSteps,
            'siblings' => Instrumentation::$inlineSiblingWalkSteps,
        ];
    }
}
