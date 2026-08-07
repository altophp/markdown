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

namespace Alto\Markdown\Tests\Extension;

use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\NodeKindBindableExtensionInterface;
use Alto\Markdown\Extension\NodeKindBindings;
use Alto\Markdown\Markdown;
use Alto\Markdown\Tests\Extension\Fixture\PublicCalloutExtension;
use PHPUnit\Framework\TestCase;

final class QualifiedKindQueryTest extends TestCase
{
    public function testQuerySelectsCustomKindByQualifiedName(): void
    {
        $document = Markdown::commonmark()
            ->with(new PublicCalloutExtension())
            ->fromString(":::note\nBody\n:::\n");

        $handles = $document->query()->kind('example:callout')->get()->all();

        self::assertCount(1, $handles);
        self::assertSame('example:callout', $handles[0]->kind()->name);
    }

    public function testQuerySelectsCoreKindByName(): void
    {
        $document = Markdown::commonmark()->fromString("# Title\n\nBody\n");

        $handles = $document->query()->kind('paragraph')->get()->all();

        self::assertCount(1, $handles);
        self::assertSame('paragraph', $handles[0]->kind()->name);
    }

    public function testUnknownKindNameReturnsAnEmptyCollection(): void
    {
        $document = Markdown::commonmark()->fromString("Body\n");

        self::assertCount(0, $document->query()->kind('example:missing')->get()->all());
    }

    public function testKnownKindStillMatchesWhenCombinedWithAnUnknownKind(): void
    {
        $document = Markdown::commonmark()->fromString("Body\n");

        $handles = $document->query()
            ->kind('example:missing')
            ->kind('paragraph')
            ->get()
            ->all();

        self::assertCount(1, $handles);
        self::assertSame('paragraph', $handles[0]->kind()->name);
    }

    public function testQualifiedNameResolvesWhenAnEarlierExtensionShiftsTheKindId(): void
    {
        $document = Markdown::commonmark()
            ->with(new QualifiedQueryReservedKindExtension(), new PublicCalloutExtension())
            ->fromString(":::note\nBody\n:::\n");

        $handles = $document->query()->kind('example:callout')->get()->all();

        self::assertCount(1, $handles);
        self::assertSame(25, $handles[0]->kind()->id);
        self::assertSame('example:callout', $handles[0]->kind()->name);
    }
}

final readonly class QualifiedQueryReservedKindExtension implements NodeKindBindableExtensionInterface
{
    public function name(): string
    {
        return 'reserved';
    }

    /**
     * @return list<string>
     */
    public function nodeKindNames(): array
    {
        return ['placeholder'];
    }

    public function bindNodeKinds(NodeKindBindings $bindings): ExtensionInterface
    {
        return $this;
    }
}
