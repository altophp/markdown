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

use Alto\Markdown\Parser\Inline\Href;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HrefTest extends TestCase
{
    #[DataProvider('provideNumericEntities')]
    public function testResolveEncodesEveryUnicodeWidth(string $entity, string $expected): void
    {
        self::assertSame($expected, Href::resolve($entity));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNumericEntities(): iterable
    {
        yield 'invalid scalar' => ['&#0;', "\u{FFFD}"];
        yield 'two bytes' => ['&#169;', '©'];
        yield 'three bytes' => ['&#8364;', '€'];
        yield 'four bytes' => ['&#128512;', '😀'];
    }
}
