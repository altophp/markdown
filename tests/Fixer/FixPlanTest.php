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

use Alto\Markdown\Fixer\FixContext;
use Alto\Markdown\Fixer\FixPlan;
use Alto\Markdown\Fixer\FixResult;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\DescribedOperation;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class FixPlanTest extends TestCase
{
    public function testFixContextDefaultsAllowBothOperationKinds(): void
    {
        $context = new FixContext();

        self::assertTrue($context->allowModelOperations);
        self::assertTrue($context->allowSourceOperations);
    }

    public function testPlanKeepsStableResultAndOperationOrder(): void
    {
        $first = self::operation(0, 1, 'A', 'first');
        $second = self::operation(2, 3, 'B', 'second');
        $third = self::operation(4, 5, 'C', 'third');

        $plan = FixPlan::fromResults([
            new FixResult(),
            new FixResult([$first, $second]),
            new FixResult(),
            new FixResult([$third]),
        ]);

        self::assertFalse($plan->isEmpty());
        self::assertSame([$first, $second, $third], $plan->operations());
    }

    public function testEmptyPlanIsNoOpAndKeepsCleanRelintClean(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $plan = FixPlan::fromResults([new FixResult()]);

        $plan->applyTo($document->model());

        self::assertTrue($plan->isEmpty());
        self::assertTrue($document->model()->journal()->isEmpty());
        self::assertTrue($document->diff()->isEmpty());
        self::assertTrue($document->lint((new LintConfig())->withRule('final-newline')->withRule('no-trailing-spaces'))->isClean());
    }

    public function testPlanAppliesAdjacentSourceFixes(): void
    {
        $document = Markdown::github()->fromString("ab\n");

        FixPlan::fromResults([
            new FixResult([
                self::operation(0, 1, 'A', 'uppercase first'),
                self::operation(1, 2, 'B', 'uppercase second'),
            ]),
        ])->applyTo($document->model());

        $diff = $document->diff();

        self::assertFalse($diff->isEmpty());
        self::assertStringContainsString('-ab', $diff->toUnifiedString());
        self::assertStringContainsString('+AB', $diff->toUnifiedString());
    }

    public function testPlanFailsClearlyForOverlappingSourceFixes(): void
    {
        $document = Markdown::github()->fromString("abcd\n");
        $plan = FixPlan::fromResults([
            new FixResult([
                self::operation(0, 3, 'ABC', 'wide fix'),
                self::operation(2, 4, 'CD', 'overlap fix'),
            ]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Overlapping fix operations "wide fix" and "overlap fix"');

        $plan->applyTo($document->model());
    }

    public function testPlanFailsClearlyForCompetingInsertionsAtSameOffset(): void
    {
        $document = Markdown::github()->fromString("ab\n");
        $plan = FixPlan::fromResults([
            new FixResult([
                self::operation(1, 1, 'X', 'first insert'),
                self::operation(1, 1, 'Y', 'second insert'),
            ]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Overlapping fix operations "first insert" and "second insert"');

        $plan->applyTo($document->model());
    }

    public function testPlanAppliesANonPatchableOperation(): void
    {
        $document = Markdown::github()->fromString("Body\n");
        $operation = new DescribedOperation('model-only fix');

        FixPlan::fromResults([new FixResult([$operation])])->applyTo($document->model());

        self::assertSame([$operation], $document->model()->journal()->operations());
    }

    public function testPlanRejectsCollisionWithAnExistingJournalPatch(): void
    {
        $document = Markdown::github()->fromString("abcd\n");
        $document->model()->journal()->record(self::operation(0, 3, 'ABC', 'existing'));
        $plan = FixPlan::fromResults([
            new FixResult([self::operation(2, 4, 'CD', 'new')]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Fix operation "new" overlaps an already journaled source patch');

        $plan->applyTo($document->model());
    }

    private static function operation(int $start, int $end, string $replacement, string $description): SourcePatchOperation
    {
        return new SourcePatchOperation(new SourcePatch(new SourceRange($start, $end), $replacement, null, $description));
    }
}
