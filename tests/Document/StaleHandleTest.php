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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Exception\StaleHandleException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\Heading;
use PHPUnit\Framework\TestCase;

final class StaleHandleTest extends TestCase
{
    public function testStaleHeadingExistsIsFalseAndContentAccessThrows(): void
    {
        $document = Markdown::commonmark()->fromString("# First\n\n## Second\n");
        $heading = $document->headings()->first();

        self::assertInstanceOf(Heading::class, $heading);

        $model = $document->model();
        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        $model->debugBumpGeneration($heading->id()->ordinal);

        self::assertFalse($heading->exists());

        $this->expectException(StaleHandleException::class);
        $this->expectExceptionMessage('Node ordinal 1 is stale.');

        $heading->text();
    }

    public function testNodeResolutionReturnsCurrentGeneration(): void
    {
        $document = Markdown::commonmark()->fromString("# First\n");
        $heading = $document->headings()->first();

        self::assertInstanceOf(Heading::class, $heading);

        $model = $document->model();
        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        $model->debugBumpGeneration($heading->id()->ordinal);
        $current = $model->node($heading->id());

        self::assertSame(1, $current->id()->generation);
        self::assertSame($heading->id()->ordinal, $current->id()->ordinal);
        self::assertTrue($current->exists());
    }

    public function testUnaffectedHeadingStaysValid(): void
    {
        $document = Markdown::commonmark()->fromString("# First\n\n## Second\n");
        $headings = $document->headings()->all();

        self::assertCount(2, $headings);

        $first = $headings[0];
        $second = $headings[1];
        $model = $document->model();

        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        $model->debugBumpGeneration($first->id()->ordinal);

        self::assertFalse($first->exists());
        self::assertTrue($second->exists());
        self::assertSame('Second', $second->text());
    }
}
