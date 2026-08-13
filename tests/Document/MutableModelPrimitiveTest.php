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
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\StaleHandleException;
use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\TestCase;

final class MutableModelPrimitiveTest extends TestCase
{
    public function testHeadingMarkdownReplacementReadsBackAndInvalidatesIndexes(): void
    {
        $document = Markdown::github()->fromString("# Old Title\n\n[link](#setup)\n");
        $model = self::parsedModel($document);
        $heading = $document->title();
        self::assertNotNull($heading);

        $newId = $model->replaceHeadingMarkdown($heading->id(), 'Setup');

        self::assertFalse($heading->exists());
        self::assertSame(1, $newId->generation);
        self::assertSame('Setup', $document->title()?->text());
        self::assertTrue($model->hasAnchor('setup'));
        self::assertFalse($model->hasAnchor('old-title'));

        $this->expectException(StaleHandleException::class);
        $heading->text();
    }

    public function testCodeBlockReplacementReadsBackAndStalesOldHandle(): void
    {
        $document = Markdown::github()->fromString("```php\necho \"old\";\n```\n");
        $model = self::parsedModel($document);
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);

        $model->setCodeBlock($block->id(), 'js', "console.log('new');\n");

        self::assertFalse($block->exists());
        self::assertNull($document->codeBlocks('php')->first());

        $updated = $document->codeBlocks('js')->first();
        self::assertNotNull($updated);
        self::assertSame('js', $updated->language());
        self::assertSame("console.log('new');\n", $updated->code());

        $this->expectException(StaleHandleException::class);
        $block->code();
    }

    public function testRemoveBlockUnlinksItFromQueriesAndStalesHandle(): void
    {
        $document = Markdown::github()->fromString("# Title\n\n## Remove Me\n\n## Keep Me\n");
        $model = self::parsedModel($document);
        $remove = $document->section('Remove Me');

        $model->removeBlock($remove->id());

        self::assertFalse($remove->exists());
        self::assertSame(['Title', 'Keep Me'], self::headingTexts($document));

        $this->expectException(StaleHandleException::class);
        $remove->title();
    }

    public function testInsertMarkdownBeforeAndAfterReparsesBlocksIntoTreeOrder(): void
    {
        $document = Markdown::github()->fromString("# Title\n\n## Existing\n");
        $model = self::parsedModel($document);
        $title = $document->title();
        $existing = $document->section('Existing');
        self::assertNotNull($title);

        $afterTitle = $model->insertMarkdownAfter($title->id(), "## After Title\n\nBody.\n");
        $beforeExisting = $model->insertMarkdownBefore($existing->id(), "## Before Existing\n");

        self::assertCount(2, $afterTitle);
        self::assertCount(1, $beforeExisting);
        self::assertSame(['Title', 'After Title', 'Before Existing', 'Existing'], self::headingTexts($document));
        self::assertSame('After Title', $model->plainText($afterTitle[0]->ordinal));
        self::assertSame('Before Existing', $model->plainText($beforeExisting[0]->ordinal));
    }

    public function testReplaceBlockWithMarkdownInsertsReparsedBlocksAndRemovesOriginal(): void
    {
        $document = Markdown::github()->fromString("# Title\n\n## Old\n\n## Keep\n");
        $model = self::parsedModel($document);
        $old = $document->section('Old');

        $inserted = $model->replaceBlockWithMarkdown($old->id(), "## New\n");

        self::assertCount(1, $inserted);
        self::assertFalse($old->exists());
        self::assertSame(['Title', 'New', 'Keep'], self::headingTexts($document));
        self::assertSame('New', $model->plainText($inserted[0]->ordinal));
    }

    public function testEmptyMarkdownInsertionsAreNoOps(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $model = self::parsedModel($document);
        $title = $document->title();
        self::assertNotNull($title);

        self::assertSame([], $model->appendMarkdownToDocument(''));
        self::assertSame([], $model->insertMarkdownBefore($title->id(), ''));
        self::assertSame([], $model->insertMarkdownAfter($title->id(), ''));
        self::assertSame(0, $model->generation());
        self::assertSame(['Title'], self::headingTexts($document));
    }

    public function testMoveAfterReordersSiblingsAndMovingOntoItselfIsANoop(): void
    {
        $document = Markdown::github()->fromString("# Title\n\n## First\n\n## Second\n\n## Third\n");
        $model = self::parsedModel($document);
        $first = $document->section('First');
        $second = $document->section('Second');

        $model->moveBlockAfter($first->id(), $second->id());

        self::assertSame(['Title', 'Second', 'First', 'Third'], self::headingTexts($document));
        self::assertSame(1, $model->generation());

        $current = $document->section('First');
        $model->moveBlockAfter($current->id(), $current->id());

        self::assertSame(1, $model->generation());
        self::assertSame(['Title', 'Second', 'First', 'Third'], self::headingTexts($document));
    }

    public function testMoveBeforeAndRemoveCoverTheFirstSiblingBoundary(): void
    {
        $document = Markdown::github()->fromString("# First\n\n# Second\n\n# Third\n");
        $model = self::parsedModel($document);
        $first = $document->section('First');
        $third = $document->section('Third');

        $model->moveBlockBefore($third->id(), $first->id());

        self::assertSame(['Third', 'First', 'Second'], self::headingTexts($document));

        $currentThird = $document->section('Third');
        $model->removeBlock($currentThird->id());

        self::assertSame(['First', 'Second'], self::headingTexts($document));
    }

    public function testMoveRejectsNodesWithDifferentParents(): void
    {
        $document = Markdown::github()->fromString("> nested\n\noutside\n");
        $model = self::parsedModel($document);
        $paragraphs = $document->query()->kind('paragraph')->get()->all();
        self::assertCount(2, $paragraphs);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('limited to siblings');

        $model->moveBlockBefore($paragraphs[0]->id(), $paragraphs[1]->id());
    }

    public function testHeadingMutationRejectsAnotherBlockKind(): void
    {
        $document = Markdown::github()->fromString("Paragraph.\n");
        $model = self::parsedModel($document);
        $paragraph = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(NodeHandle::class, $paragraph);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('unsupported kind');

        $model->replaceHeadingMarkdown($paragraph->id(), 'Heading');
    }

    public function testMutationRejectsAnAlreadyRemovedNode(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $model = self::parsedModel($document);
        $title = $document->title();
        self::assertNotNull($title);
        $id = $title->id();

        $model->removeBlock($id);

        $this->expectException(StaleHandleException::class);
        $this->expectExceptionMessage('stale or removed');

        $model->removeBlock($id);
    }

    public function testInsertedCodeBlockKeepsDetachedLanguageAndCode(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $model = self::parsedModel($document);
        $title = $document->title();
        self::assertNotNull($title);

        $inserted = $model->insertMarkdownAfter($title->id(), "```php\necho 'ok';\n```\n");

        self::assertCount(1, $inserted);
        self::assertSame('php', $model->codeBlockLanguage($inserted[0]->ordinal));
        self::assertSame("echo 'ok';\n", $model->codeBlockCode($inserted[0]->ordinal));
        self::assertSame(
            "<h1>Title</h1>\n<pre><code class=\"language-php\">echo 'ok';\n</code></pre>\n",
            $document->toHtml(),
        );
    }

    public function testInsertedContainerKeepsNestedInlineAndCodeOverrides(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $model = self::parsedModel($document);
        $title = $document->title();
        self::assertNotNull($title);

        $inserted = $model->insertMarkdownAfter(
            $title->id(),
            "> [docs](/old)\n>\n> ```php\n> echo 'ok';\n> ```\n",
        );
        $link = $document->links()->first();
        self::assertCount(1, $inserted);
        self::assertNotNull($link);

        $link = $link->setDestination('/new');

        self::assertSame('/new', $link->destination());
        self::assertStringContainsString('<a href="/new">docs</a>', $document->toHtml());
        self::assertStringContainsString('<code class="language-php">', $document->toHtml());
    }

    public function testInternalReadModelsExposeReferencesAndInlineValues(): void
    {
        $document = Markdown::github()->fromString("[docs](/guide)\n");
        $model = self::parsedModel($document);
        $link = $document->links()->first();
        self::assertNotNull($link);
        $blockOrdinal = $link->id()->ordinal;
        $inline = $model->inlineTapeView($blockOrdinal, $model->inlineSourceView($blockOrdinal))->tape;

        self::assertSame(0, $model->referenceMap()->count());
        self::assertSame('', $model->inlineTextValue($blockOrdinal, 0));
        self::assertNotSame(ParseTape::NONE, $inline->firstChildOrdinal(0));

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('unsupported kind');

        $model->inlineMutationPatchRange($link->id(), 0, InlineKind::IMAGE);
    }

    public function testExistsRejectsOrdinalsOutsideTheTape(): void
    {
        $model = self::parsedModel(Markdown::github()->fromString("# Title\n"));

        self::assertFalse($model->exists(new NodeId(0, -1)));
        self::assertFalse($model->exists(new NodeId(0, $model->htmlTape()->count())));
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
        return array_map(
            static fn(Heading $heading): string => $heading->text(),
            $document->headings()->all(),
        );
    }
}
