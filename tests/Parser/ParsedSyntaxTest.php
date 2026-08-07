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

use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ReadOnlyParseTape;
use Alto\Markdown\Parser\SyntaxParser;
use Alto\Markdown\Profile\ProfileCompiler;
use Alto\Markdown\Source\LineEnding;
use PHPUnit\Framework\TestCase;

final class ParsedSyntaxTest extends TestCase
{
    public function testSyntaxOwnsReadOnlyStateAndCreatesIndependentWorkspaceState(): void
    {
        Instrumentation::reset();
        $syntax = new SyntaxParser(ProfileCompiler::commonmark())->parse(
            "# Title\r\n\r\n[link][id]\r\n\r\n[id]: /target\r\n",
        );

        self::assertSame(1, Instrumentation::$syntaxParses);
        self::assertSame(LineEnding::CrLf, $syntax->source()->dominantEol);
        self::assertSame('commonmark', $syntax->profile()->name);
        self::assertInstanceOf(ReadOnlyParseTape::class, $syntax->tape());
        self::assertSame(0, $syntax->rootOrdinal());

        $workspaceTape = $syntax->newWorkspaceTape();
        $workspaceTape->bumpGeneration($syntax->rootOrdinal());

        self::assertSame(1, $workspaceTape->generation($syntax->rootOrdinal()));
        self::assertSame(0, $syntax->tape()->generation($syntax->rootOrdinal()));

        $referenceMap = $syntax->newReferenceMap();
        self::assertTrue($referenceMap->has('id'));
        $referenceMap->markUsed('id');

        self::assertSame([], $referenceMap->unusedLabels());
        self::assertSame(['id'], $syntax->newReferenceMap()->unusedLabels());
        self::assertSame(1, Instrumentation::$workspaceTapeClones);
        self::assertSame(2, Instrumentation::$referenceMapClones);
    }
}
