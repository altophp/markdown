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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkResolverEdgeTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::disable();
        Instrumentation::reset();
    }

    public function testTimedReferenceResolutionRecordsHitsMissesAndLabelEmphasis(): void
    {
        Instrumentation::measure();
        $document = Markdown::commonmark()->fromString(
            "[*hit*][target] and [miss][unknown].\n\n[target]: /url\n",
        );

        $html = $document->toHtml();

        self::assertStringContainsString('<a href="/url"><em>hit</em></a>', $html);
        self::assertStringContainsString('[miss][unknown]', $html);
        self::assertSame(1, Instrumentation::$refLookupHits);
        self::assertGreaterThanOrEqual(1, Instrumentation::$refLookupMisses);
        self::assertGreaterThanOrEqual(1, Instrumentation::$refFailed);
        self::assertGreaterThan(0, Instrumentation::$stageEnters['emphasis'] ?? 0);
    }

    #[DataProvider('provideInvalidInlineLinks')]
    public function testInvalidInlineLinkSyntaxRemainsLiteral(string $source): void
    {
        $html = Markdown::commonmark()->fromString($source)->toHtml();

        self::assertStringStartsWith('<p>[x]', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidInlineLinks(): iterable
    {
        yield 'control byte' => ["[x](a\x01b)\n"];
        yield 'unbalanced destination' => ["[x](a(b)\n"];
        yield 'nested parenthesized title' => ["[x](url (bad(title)))\n"];
        yield 'unterminated title' => ["[x](url \"unterminated)\n"];
    }
}
