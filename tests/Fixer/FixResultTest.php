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

namespace Alto\Markdown\Tests\Fixer;

use Alto\Markdown\Fixer\FixResult;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class FixResultTest extends TestCase
{
    public function testEmptyFixResultIsNoOp(): void
    {
        $document = Markdown::github()->fromString("a\n");
        $result = new FixResult();

        $result->applyTo($document->model());

        self::assertTrue($result->isEmpty());
        self::assertTrue($document->model()->journal()->isEmpty());
        self::assertTrue($document->diff()->isEmpty());
    }

    public function testFixResultKeepsOperationsInOrder(): void
    {
        $first = self::operation(0, 1, 'A', 'first');
        $second = self::operation(2, 3, 'B', 'second');
        $result = new FixResult([$first, $second]);

        self::assertFalse($result->isEmpty());
        self::assertSame([$first, $second], $result->operations);
    }

    public function testApplyRecordsOperationsWithAffectedRanges(): void
    {
        $document = Markdown::github()->fromString("a\nb\n");
        $first = self::operation(0, 1, 'A', 'first');
        $second = self::operation(2, 3, 'B', 'second');

        new FixResult([$first, $second])->applyTo($document->model());

        self::assertSame([$first, $second], $document->model()->journal()->operations());
        self::assertSame(0, $document->model()->journal()->entries()[0]->affectedRange?->startOffset);
        self::assertSame(3, $document->model()->journal()->entries()[1]->affectedRange?->endOffset);
    }

    public function testAppliedFixOperationsLowerThroughPreviewDiff(): void
    {
        $document = Markdown::github()->fromString("a\nb\n");

        new FixResult([
            self::operation(0, 1, 'A', 'uppercase first line'),
            self::operation(2, 3, 'B', 'uppercase second line'),
        ])->applyTo($document->model());

        $diff = $document->diff();

        self::assertFalse($diff->isEmpty());
        self::assertStringContainsString('-a', $diff->toUnifiedString());
        self::assertStringContainsString('+A', $diff->toUnifiedString());
        self::assertStringContainsString('-b', $diff->toUnifiedString());
        self::assertStringContainsString('+B', $diff->toUnifiedString());
    }

    private static function operation(int $start, int $end, string $replacement, string $description): SourcePatchOperation
    {
        return new SourcePatchOperation(new SourcePatch(new SourceRange($start, $end), $replacement, null, $description));
    }
}
