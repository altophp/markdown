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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\UnsupportedDocumentModelException;
use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Operation\BlockInsertPosition;
use Alto\Markdown\Operation\EditJournal;
use Alto\Markdown\Operation\InsertBlockOperation;
use Alto\Markdown\Operation\MoveBlockOperation;
use Alto\Markdown\Operation\RemoveBlockOperation;
use Alto\Markdown\Operation\ReplaceBlockOperation;
use Alto\Markdown\Operation\ReplaceCodeBlockContentOperation;
use Alto\Markdown\Operation\ReplaceFrontMatterContentOperation;
use Alto\Markdown\Operation\SetCodeBlockLanguageOperation;
use Alto\Markdown\Operation\SetInlineLinkOperation;
use Alto\Markdown\Source\SourceDocument;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class BlockOperationTest extends TestCase
{
    public function testInsertBlockOperationUpdatesTreeOrderAndPatchUsesOriginalOffset(): void
    {
        $source = "# Title\n\n## Existing\n";
        $document = Markdown::github()->fromString($source);
        $existing = $document->section('Existing');
        $range = new SourceRange($existing->range()->startOffset, $existing->range()->startOffset);
        $operation = new InsertBlockOperation($existing->id(), "## Added\n", BlockInsertPosition::Before, $range);

        $operation->apply($document->model());
        $patch = $operation->toPatch($document->model());

        self::assertSame(['Title', 'Added', 'Existing'], self::headingTexts($document));
        self::assertNotNull($patch);
        self::assertSame($range, $patch->range);
        self::assertSame($range, $patch->affectedRange);
        self::assertSame("## Added\n", $patch->replacement);
    }

    public function testRemoveBlockOperationUpdatesTreeOrderAndPatchUsesOriginalRange(): void
    {
        $source = "# Title\n\n## Removed\n\n## Keep\n";
        $document = Markdown::github()->fromString($source);
        $removed = $document->section('Removed');
        $range = $removed->range();
        $operation = new RemoveBlockOperation($removed->id(), $range);

        $operation->apply($document->model());
        $patch = $operation->toPatch($document->model());

        self::assertSame(['Title', 'Keep'], self::headingTexts($document));
        self::assertSame($range, $patch->range);
        self::assertSame('', $patch->replacement);
    }

    public function testReplaceBlockOperationReturnsNewBlocksAndOneOriginalRangePatch(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nOld.\n");
        $old = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(NodeHandle::class, $old);
        $range = new SourceRange(9, 14);
        $operation = new ReplaceBlockOperation($old->id(), "## New\n", $range, "## New\n", 'replace test block');

        $inserted = $operation->applyAndReturn(self::parsedModel($document));
        $patch = $operation->toPatch($document->model());

        self::assertSame('replace test block', $operation->describe());
        self::assertCount(1, $inserted);
        self::assertSame(['Title', 'New'], self::headingTexts($document));
        self::assertNotNull($patch);
        self::assertSame($range, $patch->range);
        self::assertSame("## New\n", $patch->replacement);

        $second = Markdown::github()->fromString("# Title\n\nOld.\n");
        $secondOld = $second->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(NodeHandle::class, $secondOld);
        (new ReplaceBlockOperation($secondOld->id(), "## Applied\n", $range, "## Applied\n"))
            ->apply($second->model());
        self::assertSame(['Title', 'Applied'], self::headingTexts($second));
    }

    public function testMoveBlockOperationUpdatesSiblingOrderAndKeepsAffectedOriginalRange(): void
    {
        $source = "# Title\n\n## First\n\n## Second\n\n## Third\n";
        $document = Markdown::github()->fromString($source);
        $first = $document->section('First');
        $third = $document->section('Third');
        $range = $third->range();
        $operation = new MoveBlockOperation($third->id(), $first->id(), BlockInsertPosition::Before, $range);

        $operation->apply($document->model());

        self::assertSame(['Title', 'Third', 'First', 'Second'], self::headingTexts($document));
        self::assertNull($operation->toPatch($document->model()));
        self::assertSame($range->startOffset, $operation->affectedRange()->startOffset);
    }

    public function testMoveBlockOperationCanMoveAfterAnchor(): void
    {
        $document = Markdown::github()->fromString("# Title\n\n## First\n\n## Second\n\n## Third\n");
        $first = $document->section('First');
        $third = $document->section('Third');
        $operation = new MoveBlockOperation(
            $first->id(),
            $third->id(),
            BlockInsertPosition::After,
            $first->range(),
        );

        self::assertSame('move block after anchor', $operation->describe());
        $operation->apply($document->model());

        self::assertSame(['Title', 'Second', 'Third', 'First'], self::headingTexts($document));
    }

    public function testSetCodeBlockLanguageOperationUpdatesCodeBlockIndex(): void
    {
        $document = Markdown::github()->fromString("~~~  js title=demo\r\necho \"ok\";\r\n~~~\r\n");
        $block = $document->codeBlocks()->first();
        self::assertNotNull($block);
        $range = new SourceRange(5, 7);
        $operation = new SetCodeBlockLanguageOperation($block->id(), 'php', $range);

        $operation->apply($document->model());
        $patch = $operation->toPatch($document->model());

        self::assertSame('php', $document->codeBlocks('php')->first()?->language());
        self::assertSame($range, $patch->range);
        self::assertSame('php', $patch->replacement);
    }

    public function testReplaceCodeBlockContentOperationUpdatesCodeAndPatchUsesOriginalRange(): void
    {
        $document = Markdown::github()->fromString("```php\necho \"old\";\n```\n");
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);
        $range = $block->range();
        $operation = new ReplaceCodeBlockContentOperation($block->id(), 'php', "echo \"new\";\n", $range);

        $operation->apply($document->model());
        $patch = $operation->toPatch($document->model());

        self::assertSame("echo \"new\";\n", $document->codeBlocks('php')->first()?->code());
        self::assertSame($range, $patch->range);
        self::assertSame("```php\necho \"new\";\n```\n", $patch->replacement);
    }

    public function testBlockOperationsRejectAnotherDocumentModelImplementation(): void
    {
        $id = new NodeId(0, 0);
        $range = new SourceRange(0, 0);
        $operations = [
            new MoveBlockOperation($id, $id, BlockInsertPosition::Before, $range),
            new SetInlineLinkOperation($id, 0, 0, '/target', null, null, 'set link', $range, ''),
            new InsertBlockOperation($id, "Text\n", BlockInsertPosition::Before, $range),
            new RemoveBlockOperation($id, $range),
            new ReplaceBlockOperation($id, 'Text', $range, 'Text'),
            new ReplaceCodeBlockContentOperation($id, null, "code\n", $range),
            new ReplaceFrontMatterContentOperation($id, "key: value\n", $range),
            new SetCodeBlockLanguageOperation($id, 'php', $range),
        ];
        $model = self::foreignModel();

        foreach ($operations as $operation) {
            try {
                $operation->apply($model);
                self::fail(\sprintf('Expected %s to reject the document model.', $operation::class));
            } catch (UnsupportedDocumentModelException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame('set link', $operations[1]->describe());
    }

    private static function foreignModel(): DocumentModel
    {
        return new class implements DocumentModel {
            public function generation(): int
            {
                throw new \LogicException('Not used.');
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
                throw new \LogicException('Not used.');
            }
        };
    }

    private static function parsedModel(MarkdownDocument $document): ParsedDocumentModel
    {
        $model = $document->model();
        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        return $model;
    }

    /**
     * @return list<string>
     */
    private static function headingTexts(MarkdownDocument $document): array
    {
        self::parsedModel($document);

        return array_map(
            static fn (Heading $heading): string => $heading->text(),
            $document->headings()->all(),
        );
    }
}
