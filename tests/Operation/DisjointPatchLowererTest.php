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

namespace Alto\Markdown\Tests\Operation;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\PatchLoweringException;
use Alto\Markdown\Exception\SourcePatchException;
use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Operation\BlockInsertPosition;
use Alto\Markdown\Operation\DescribedOperation;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use Alto\Markdown\Operation\EditJournal;
use Alto\Markdown\Operation\MoveBlockOperation;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Source\SourceDocument;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Tests\Support\SemanticTreeComparator;
use PHPUnit\Framework\TestCase;

final class DisjointPatchLowererTest extends TestCase
{
    public function testAppliesSortedDisjointPatchesInOnePass(): void
    {
        $lowerer = new DisjointPatchLowerer();
        $source = "a\nb\nc\n";

        $bytes = $lowerer->apply($source, [
            new SourcePatch(new SourceRange(4, 5), 'C'),
            new SourcePatch(new SourceRange(2, 3), 'B'),
        ]);

        self::assertSame("a\nB\nC\n", $bytes);
    }

    public function testApplyingNoPatchesReturnsTheOriginalString(): void
    {
        self::assertSame('original', new DisjointPatchLowerer()->apply('original', []));
    }

    public function testApplyRejectsInvalidAndOutOfBoundsRanges(): void
    {
        $lowerer = new DisjointPatchLowerer();

        try {
            $lowerer->apply('abc', [new SourcePatch(new SourceRange(-1, 1), 'x')]);
            self::fail('Expected the negative range to fail.');
        } catch (SourcePatchException $error) {
            self::assertSame('Source patches must use valid original source ranges.', $error->getMessage());
        }

        $this->expectException(SourcePatchException::class);
        $this->expectExceptionMessage('Source patches must not extend beyond the original source length.');

        $lowerer->apply('abc', [new SourcePatch(new SourceRange(1, 4), 'x')]);
    }

    public function testFallbackRejectsAnotherDocumentModelImplementation(): void
    {
        $document = Markdown::commonmark()->fromString("Body\n");
        $journal = $document->model()->journal();
        $journal->record(new DescribedOperation('foreign edit'), new SourceRange(0, 4));
        $model = new class($journal) implements DocumentModel {
            public function __construct(private readonly EditJournal $editJournal)
            {
            }

            public function generation(): int
            {
                return 0;
            }

            public function root(): NodeHandle
            {
                throw new \LogicException('Not used.');
            }

            public function node(NodeId $id): NodeHandle
            {
                throw new \LogicException('Not used.');
            }

            public function source(): SourceDocument
            {
                throw new \LogicException('Not used.');
            }

            public function journal(): EditJournal
            {
                return $this->editJournal;
            }
        };

        $this->expectException(PatchLoweringException::class);
        $this->expectExceptionMessage('Operation "foreign edit" cannot lower to one disjoint source patch.');

        new DisjointPatchLowerer()->lower($model, $journal);
    }

    public function testLoweringResultKeepsPatchesSortedByOriginalOffset(): void
    {
        $document = Markdown::github()->fromString("a\nb\nc\n");
        $document->model()->journal()->record(new PatchOperation(new SourcePatch(new SourceRange(4, 5), 'C')));
        $document->model()->journal()->record(new PatchOperation(new SourcePatch(new SourceRange(2, 3), 'B')));

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("a\nB\nC\n", $result->bytes);
        self::assertSame([2, 4], array_map(static fn (SourcePatch $patch): int => $patch->range->startOffset, $result->patches));
    }

    public function testRejectsOverlappingPatches(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Overlapping source patches');

        new DisjointPatchLowerer()->apply('abcdef', [
            new SourcePatch(new SourceRange(1, 4), 'X'),
            new SourcePatch(new SourceRange(3, 5), 'Y'),
        ]);
    }

    public function testPreviewDiffLowersPatchableJournalWithoutClearingIt(): void
    {
        $document = Markdown::github()->fromString("```php\necho \"old\";\n```\n");
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("echo \"new\";\n");

        $diff = $document->diff();

        self::assertFalse($diff->isEmpty());
        self::assertStringContainsString('echo "new";', $diff->toUnifiedString());
        self::assertFalse($document->model()->journal()->isEmpty());
    }

    public function testPreviewDiffRejectsNonPatchableOperationClearly(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $document->model()->journal()->record(new DescribedOperation('model-only edit'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Operation "model-only edit" cannot lower to one disjoint source patch and has no affected source range.');

        $document->diff();
    }

    public function testNonPatchableOperationWithRangeUsesLoudFallback(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $document->model()->journal()->record(new DescribedOperation('model-only edit'), new SourceRange(0, 7));

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Title\n", $result->bytes);
        self::assertCount(1, $result->fallbacks);
        self::assertSame('ancestor', $result->fallbacks[0]->kind);
        self::assertSame('Operation "model-only edit" cannot lower to one disjoint source patch.', $result->fallbacks[0]->reason);
    }

    public function testLoweredBytesReparseToEditedModelForDisjointPatch(): void
    {
        $factory = Markdown::github();
        $document = $factory->fromString("```php\necho \"old\";\n```\n");
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("echo \"new\";\n");

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());
        $reparsed = $factory->fromString($result->bytes);
        $comparison = new SemanticTreeComparator()->compare($document->model(), $reparsed->model());

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }

    public function testSameBlockDoubleEditWidensToAncestorFallback(): void
    {
        $document = Markdown::github()->fromString("```php\necho \"one\";\n```\n");
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block = $block->replaceCode("echo \"two\";\n");
        $block->replaceCode("echo \"three\";\n");

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("```php\necho \"three\";\n```\n", $result->bytes);
        self::assertCount(1, $result->fallbacks);
        self::assertSame('ancestor', $result->fallbacks[0]->kind);
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    public function testAncestorFallbackPreservesCrLf(): void
    {
        $document = Markdown::github()->fromString("```php\r\necho \"one\";\r\n```\r\n");
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block = $block->replaceCode("echo \"two\";\n");
        $block->replaceCode("echo \"three\";\n");

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("```php\r\necho \"three\";\r\n```\r\n", $result->bytes);
        self::assertCount(1, $result->fallbacks);
        self::assertSame('ancestor', $result->fallbacks[0]->kind);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $result->bytes));
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    public function testAncestorFallbackPreservesBareCr(): void
    {
        $document = Markdown::github()->fromString("```php\recho \"one\";\r```\r");
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block = $block->replaceCode("echo \"two\";\n");
        $block->replaceCode("echo \"three\";\n");

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("```php\recho \"three\";\r```\r", $result->bytes);
        self::assertCount(1, $result->fallbacks);
        self::assertSame('ancestor', $result->fallbacks[0]->kind);
        self::assertStringNotContainsString("\n", $result->bytes);
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    public function testNestedHeadingAndBodyEditStaysPatchable(): void
    {
        $document = Markdown::github()->fromString("# Project\n\n## Install\n\nOld.\n\n## Usage\n\nUse.\n");

        $document->section('Install')->rename('Setup');
        $document->section('Setup')->append("Added.\n");

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame([], $result->fallbacks);
        self::assertStringContainsString("## Setup\n\nOld.\n\nAdded.\n\n## Usage\n\nUse.", $result->bytes);
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    public function testAdjacentBlockEditsStayDisjointWithoutFallback(): void
    {
        $document = Markdown::github()->fromString("```php\necho \"one\";\n```\n\n```js\nconsole.log(\"one\");\n```\n");
        $blocks = $document->codeBlocks()->all();
        self::assertCount(2, $blocks);

        $blocks[0]->replaceCode("echo \"two\";\n");
        $blocks[1]->replaceCode("console.log(\"two\");\n");

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame([], $result->fallbacks);
        self::assertStringContainsString("```php\necho \"two\";\n```\n", $result->bytes);
        self::assertStringContainsString("```js\nconsole.log(\"two\");\n```\n", $result->bytes);
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    public function testEditPlusRemoveUsesRootFallbackWithoutCorruption(): void
    {
        $document = Markdown::github()->fromString("# Project\n\n## Install\n\n```php\necho \"old\";\n```\n\n## Usage\n\nUse.\n");
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block->replaceCode("echo \"new\";\n");
        $document->section('Install')->remove();

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertCount(1, $result->fallbacks);
        self::assertSame('root', $result->fallbacks[0]->kind);
        self::assertStringNotContainsString('Install', $result->bytes);
        self::assertStringNotContainsString('echo', $result->bytes);
        self::assertStringContainsString("## Usage\n\nUse\\.", $result->bytes);
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    public function testMoveBlockUsesLoudRootFallback(): void
    {
        $document = Markdown::github()->fromString("# Project\n\n## First\n\nOne.\n\n## Second\n\nTwo.\n");
        $first = $document->section('First');
        $second = $document->section('Second');
        $range = new SourceRange($first->range()->startOffset, $second->range()->endOffset);
        $operation = new MoveBlockOperation($second->id(), $first->id(), BlockInsertPosition::Before, $range);
        $operation->apply($document->model());
        $document->model()->journal()->record($operation, $range);

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertCount(1, $result->fallbacks);
        self::assertSame('root', $result->fallbacks[0]->kind);
        self::assertStringContainsString("## Second\n\n## First\n\nOne\\.\n\nTwo\\.", $result->bytes);
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    public function testRootFallbackPreservesBomAndCrLf(): void
    {
        $document = Markdown::github()->fromString("\xEF\xBB\xBF# Project\r\n\r\n## First\r\n\r\n## Second\r\n");
        $first = $document->headings(2)->all()[0];
        $second = $document->headings(2)->all()[1];
        $range = new SourceRange($first->range()->startOffset, $second->range()->endOffset);
        $operation = new MoveBlockOperation($second->id(), $first->id(), BlockInsertPosition::Before, $range);
        $operation->apply($document->model());
        $document->model()->journal()->record($operation, $range);

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("\xEF\xBB\xBF# Project\r\n\r\n## Second\r\n\r\n## First\r\n", $result->bytes);
        self::assertCount(1, $result->fallbacks);
        self::assertSame('root', $result->fallbacks[0]->kind);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $result->bytes));
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    public function testRootFallbackPreservesBareCr(): void
    {
        $document = Markdown::github()->fromString("# Project\r\r## First\r\r## Second\r");
        $first = $document->headings(2)->all()[0];
        $second = $document->headings(2)->all()[1];
        $range = new SourceRange($first->range()->startOffset, $second->range()->endOffset);
        $operation = new MoveBlockOperation($second->id(), $first->id(), BlockInsertPosition::Before, $range);
        $operation->apply($document->model());
        $document->model()->journal()->record($operation, $range);

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Project\r\r## Second\r\r## First\r", $result->bytes);
        self::assertCount(1, $result->fallbacks);
        self::assertSame('root', $result->fallbacks[0]->kind);
        self::assertStringNotContainsString("\n", $result->bytes);
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    public function testZeroWidthEnsuresStayPatchable(): void
    {
        $document = Markdown::github()->fromString("# Project\n");

        $document->ensure()
            ->section('Changelog', 2)
            ->section('Usage', 2)
            ->apply();

        $result = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame([], $result->fallbacks);
        self::assertStringContainsString("# Project\n\n## Changelog\n\n## Usage\n", $result->bytes);
        $this->assertReparsesToEditedModel($document, $result->bytes);
    }

    private function assertReparsesToEditedModel(MarkdownDocument $document, string $bytes): void
    {
        $reparsed = Markdown::github()->fromString($bytes);
        $comparison = new SemanticTreeComparator()->compare($document->model(), $reparsed->model());

        self::assertTrue($comparison->isEqual(), $comparison->message());
    }
}

final readonly class PatchOperation implements \Alto\Markdown\Operation\Operation
{
    public function __construct(private SourcePatch $patch)
    {
    }

    public function describe(): string
    {
        return 'test patch';
    }

    public function apply(DocumentModel $model): void
    {
    }

    public function toPatch(DocumentModel $model): SourcePatch
    {
        return $this->patch;
    }
}
