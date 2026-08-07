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

namespace Alto\Markdown\Tests\Conformance;

use PHPUnit\Framework\TestCase;

final class ExampleFilterTest extends TestCase
{
    public function testEmptyFilterMatchesEverything(): void
    {
        self::assertTrue((new ExampleFilter())->matches($this->example()));
    }

    public function testSectionFilterMatchesExactSectionOnly(): void
    {
        self::assertTrue((new ExampleFilter(section: 'Tabs'))->matches($this->example()));
        self::assertFalse((new ExampleFilter(section: 'tabs'))->matches($this->example()));
        self::assertFalse((new ExampleFilter(section: 'Lists'))->matches($this->example()));
    }

    public function testExampleFilterMatchesExactNumberOnly(): void
    {
        self::assertTrue((new ExampleFilter(example: 42))->matches($this->example()));
        self::assertFalse((new ExampleFilter(example: 7))->matches($this->example()));
    }

    public function testSectionAndExampleMustBothMatch(): void
    {
        self::assertTrue((new ExampleFilter(section: 'Tabs', example: 42))->matches($this->example()));
        self::assertFalse((new ExampleFilter(section: 'Tabs', example: 7))->matches($this->example()));
        self::assertFalse((new ExampleFilter(section: 'Lists', example: 42))->matches($this->example()));
    }

    private function example(): SpecExample
    {
        return new SpecExample(markdown: 'x', html: 'y', example: 42, section: 'Tabs', startLine: 1, endLine: 2);
    }
}
