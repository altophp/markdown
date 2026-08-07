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

use Alto\Markdown\Parser\CaseFold;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ReferenceMap;
use PHPUnit\Framework\TestCase;

final class ReferenceMapTest extends TestCase
{
    public function testStoresAndLooksUpByLabel(): void
    {
        $map = new ReferenceMap();

        self::assertTrue($map->add('foo', '/url', 'title'));
        self::assertTrue($map->has('foo'));
        self::assertSame(['destination' => '/url', 'title' => 'title'], $map->lookup('foo'));
        self::assertSame(1, $map->count());
    }

    public function testTitleMayBeNull(): void
    {
        $map = new ReferenceMap();
        $map->add('foo', '/url', null);

        self::assertSame(['destination' => '/url', 'title' => null], $map->lookup('foo'));
    }

    public function testUnknownLabelLooksUpToNull(): void
    {
        $map = new ReferenceMap();

        self::assertNull($map->lookup('missing'));
        self::assertFalse($map->has('missing'));
    }

    public function testFirstDefinitionWins(): void
    {
        $map = new ReferenceMap();

        self::assertTrue($map->add('foo', 'first', null));
        self::assertFalse($map->add('foo', 'second', 'ignored'));

        $definition = $map->lookup('foo');
        self::assertNotNull($definition);
        self::assertSame('first', $definition['destination']);
        self::assertSame(1, $map->count());
    }

    public function testDefineReturnsTheStoredNormalizedLabel(): void
    {
        $map = new ReferenceMap();

        self::assertSame('foo bar', $map->define('[ Foo   Bar ]', '/url', null));
        self::assertSame(['destination' => '/url', 'title' => null], $map->lookup('foo bar'));
    }

    public function testResolveLooksUpAndMarksTheDefinitionUsed(): void
    {
        $map = new ReferenceMap();
        $map->add('Foo Bar', '/url', null);

        self::assertSame(['foo bar'], $map->unusedLabels());
        self::assertSame(['destination' => '/url', 'title' => null], $map->resolve('[ FOO   BAR ]'));
        self::assertSame([], $map->unusedLabels());
        self::assertNull($map->resolve('missing'));
    }

    public function testAsciiMatchingIsCaseInsensitive(): void
    {
        $map = new ReferenceMap();
        $map->add('FOO', '/url', null);

        self::assertTrue($map->has('foo'));
        self::assertTrue($map->has('Foo'));
        self::assertTrue($map->has('fOo'));
    }

    /**
     * Spec section "Link reference definitions" example with Greek letters:
     * [ΑΓΩ] defines the label matched by [αγω].
     */
    public function testGreekCaseFold(): void
    {
        $map = new ReferenceMap();
        $map->add('ΑΓΩ', '/url', null);

        self::assertTrue($map->has('αγω'));
        self::assertSame(ReferenceMap::normalize('αγω'), ReferenceMap::normalize('ΑΓΩ'));
    }

    public function testCyrillicCaseFold(): void
    {
        $map = new ReferenceMap();
        $map->add('ПРИВЕТ', '/url', null);

        self::assertTrue($map->has('привет'));
        self::assertTrue($map->has('Привет'));
        self::assertSame(ReferenceMap::normalize('привет'), ReferenceMap::normalize('ПРИВЕТ'));
    }

    public function testCaseFoldPreservesFourByteAndMalformedSequences(): void
    {
        self::assertSame('😀', CaseFold::fold('😀'));
        self::assertSame("\xF0", CaseFold::fold("\xF0"));
        self::assertSame("\xE2\x82", CaseFold::fold("\xE2\x82"));
    }

    /**
     * Full folding (CaseFolding.txt status F) expands a single codepoint to
     * several: U+00DF and U+1E9E both fold to "ss", so a sharp-s label matches
     * an upper-case "SS" label.
     */
    public function testFullFoldExpandsSharpS(): void
    {
        self::assertSame('ss', ReferenceMap::normalize('ß'));
        self::assertSame('ss', ReferenceMap::normalize('ẞ'));
        self::assertSame(ReferenceMap::normalize('ß'), ReferenceMap::normalize('SS'));

        $map = new ReferenceMap();
        $map->add('ß', '/url', null);
        self::assertTrue($map->has('SS'));
    }

    public function testWhitespaceIsTrimmedAndCollapsed(): void
    {
        self::assertSame('foo bar', ReferenceMap::normalize('  foo   bar  '));
        self::assertSame('foo bar', ReferenceMap::normalize("foo\n\tbar"));

        $map = new ReferenceMap();
        $map->add('Foo   Bar', '/url', null);
        self::assertTrue($map->has('foo bar'));
    }

    public function testSlowNormalizationReportsCaseFoldWork(): void
    {
        Instrumentation::measure();

        try {
            self::assertSame('foo bar', ReferenceMap::normalize(' FOO  BAR '));
            self::assertSame(1, Instrumentation::$caseFoldCalls);
            self::assertSame(10, Instrumentation::$caseFoldBytes);
        } finally {
            Instrumentation::disable();
        }
    }

    public function testSurroundingBracketsAreStripped(): void
    {
        self::assertSame(ReferenceMap::normalize('foo'), ReferenceMap::normalize('[foo]'));

        $map = new ReferenceMap();
        $map->add('foo', '/url', null);
        self::assertTrue($map->has('[Foo]'));
    }

    public function testEmptyLabelIsIgnored(): void
    {
        $map = new ReferenceMap();

        self::assertFalse($map->add('   ', '/url', null));
        self::assertFalse($map->add('[]', '/url', null));
        self::assertSame(0, $map->count());
    }
}
