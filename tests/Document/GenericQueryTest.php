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

use Alto\Markdown\Markdown;
use Alto\Markdown\Node\NodeHandle;
use PHPUnit\Framework\TestCase;

final class GenericQueryTest extends TestCase
{
    public function testQueryResolvesCoreKindsByStableName(): void
    {
        $document = Markdown::commonmark()->fromString("# Title\n\nFirst.\n\nSecond.\n");

        $handles = $document->query()
            ->kind('paragraph')
            ->get()
            ->all();

        self::assertContainsOnlyInstancesOf(NodeHandle::class, $handles);
        self::assertCount(2, $handles);
        self::assertSame('paragraph', $handles[0]->kind()->name);
    }

    public function testQueryReturnsExtensionKindsWithoutCoreEnumChanges(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            | A | B |
            | - | - |
            | 1 | 2 |
            MD);

        $handles = $document->query()
            ->kind('gfm:table')
            ->get()
            ->all();

        self::assertContainsOnlyInstancesOf(NodeHandle::class, $handles);
        self::assertCount(1, $handles);
        self::assertSame('gfm:table', $handles[0]->kind()->name);
    }

    public function testQueryPredicatesReceiveCurrentHandles(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            ## Install
            MD);

        $handles = $document->query()
            ->kind('atx-heading')
            ->where(static fn (NodeHandle $handle): bool => 0 === $handle->id()->generation)
            ->get()
            ->all();

        self::assertCount(2, $handles);
    }

    public function testQueryPredicatesCanRejectIndividualHandles(): void
    {
        $document = Markdown::github()->fromString("# Keep\n\n## Reject\n");

        $handles = $document->query()
            ->kind('atx-heading')
            ->where(static fn (NodeHandle $handle): bool => 0 === $handle->range()->startOffset)
            ->get()
            ->all();

        self::assertCount(1, $handles);
    }
}
