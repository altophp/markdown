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

use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\TestCase;

final class InlineParserReuseTest extends TestCase
{
    public function testFactoryReusesOneInlineParserAcrossBlocksCellsAndDocuments(): void
    {
        Instrumentation::reset();
        $factory = Markdown::gfm();

        $first = $factory->fromString(<<<'MD'
            [First][target] and *emphasis*.

            | Rich |
            | --- |
            | `one` |

            [target]: /first
            MD)->toHtml();

        $second = $factory->fromString(<<<'MD'
            [Second][target] and **strong**.

            | Rich |
            | --- |
            | `two` |

            [target]: /second
            MD)->toHtml();

        self::assertStringContainsString('<a href="/first">First</a>', $first);
        self::assertStringContainsString('<code>one</code>', $first);
        self::assertStringNotContainsString('/second', $first);
        self::assertStringContainsString('<a href="/second">Second</a>', $second);
        self::assertStringContainsString('<code>two</code>', $second);
        self::assertStringNotContainsString('/first', $second);
        self::assertSame(1, Instrumentation::$inlineParserConstructions);
        self::assertSame(4, Instrumentation::$inlineParses);
    }

    public function testReusedParserDoesNotLeakDelimiterStateBetweenParses(): void
    {
        Instrumentation::reset();
        $factory = Markdown::gfm();
        $unmatched = $factory->fromString("a* b* c*\n");
        $matched = $factory->fromString("*clean* and ~~deleted~~\n");

        self::assertSame("<p>a* b* c*</p>\n", $unmatched->toHtml());
        self::assertSame("<p><em>clean</em> and <del>deleted</del></p>\n", $matched->toHtml());
        self::assertSame("<p>a* b* c*</p>\n", $unmatched->toHtml());
        self::assertSame(1, Instrumentation::$inlineParserConstructions);
        self::assertSame(2, Instrumentation::$inlineParses);
    }
}
