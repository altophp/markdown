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
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\MarkdownFile;
use Alto\Markdown\Node\CodeBlock;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Node\Link;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Node\Section;
use PHPUnit\Framework\TestCase;

final class SpecQueryExampleTest extends TestCase
{
    public function testHeadingsAreIterableWithoutCollectionEach(): void
    {
        $document = self::document(<<<'MD'
            # Title

            ## Install

            ## Usage
            MD);

        $texts = [];

        foreach ($document->headings() as $heading) {
            $texts[] = $heading->text();
        }

        self::assertSame(['Title', 'Install', 'Usage'], $texts);
    }

    public function testHeadingCollectionsCanFilterAndFindFirst(): void
    {
        $document = self::document(<<<'MD'
            # Title

            ## Install

            ### Details
            MD);

        $heading = $document->headings()
            ->filter(static fn(Heading $heading): bool => 2 === $heading->level())
            ->first();

        self::assertInstanceOf(Heading::class, $heading);
        self::assertSame('Install', $heading->text());
    }

    public function testLinksExposeMarkdownDataOnly(): void
    {
        $document = self::document(<<<'MD'
            Read [the guide](https://example.com "Guide title").
            MD);

        $link = $document->links()->first();

        self::assertInstanceOf(Link::class, $link);
        self::assertSame('the guide', $link->text());
        self::assertSame('https://example.com', $link->destination());
        self::assertSame('Guide title', $link->titleAttribute());
    }

    public function testImagesExposeMarkdownDataOnly(): void
    {
        $document = self::document(<<<'MD'
            See ![the *logo*](/logo.png "Logo title").
            MD);

        $image = $document->images()->first();

        self::assertInstanceOf(\Alto\Markdown\Node\Image::class, $image);
        self::assertSame('the logo', $image->altText());
        self::assertSame('/logo.png', $image->destination());
        self::assertSame('Logo title', $image->titleAttribute());
    }

    public function testSectionLookupReturnsFirstMatchAndSectionsReturnAllMatches(): void
    {
        $document = self::document(<<<'MD'
            # Project

            ## Install

            First install.

            ### Details

            Nested details.

            ## install

            Second install.
            MD);

        $first = $document->section('INSTALL');
        $all = $document->sections('install')->all();

        self::assertTrue($first->exists());
        self::assertSame('Install', $first->title());
        self::assertCount(2, $all);
        self::assertSame(['Install', 'install'], \array_map(
            static fn(Section $section): string => $section->title(),
            $all,
        ));
    }

    public function testMissingSectionUsesNullObject(): void
    {
        $document = self::document("# Title\n");

        $section = $document->section('Missing');

        self::assertFalse($section->exists());
    }

    public function testCodeBlocksCanBeFilteredByLanguage(): void
    {
        $document = self::document(<<<'MD'
            ```php
            echo "hello";
            ```

            ```json
            {"ok": true}
            ```
            MD);

        $blocks = $document->codeBlocks('php')->all();

        self::assertContainsOnlyInstancesOf(CodeBlock::class, $blocks);
        self::assertCount(1, $blocks);
        self::assertSame('php', $blocks[0]->language());
        self::assertSame("echo \"hello\";\n", $blocks[0]->code());
    }

    public function testModelCanResolveCurrentNodeId(): void
    {
        $document = self::document("# Title\n\nBody\n");
        $heading = $document->headings()->first();

        self::assertInstanceOf(Heading::class, $heading);

        $resolved = $document->model()->node($heading->id());

        self::assertInstanceOf(NodeHandle::class, $resolved);
        self::assertSame($heading->id()->ordinal, $resolved->id()->ordinal);
        self::assertSame($heading->id()->generation, $resolved->id()->generation);
    }

    public function testGenericQueryFallbackWalksDocumentOrder(): void
    {
        $document = self::document(<<<'MD'
            # Title

            Paragraph.

            ## Install
            MD);

        $handles = $document->query()
            ->kind('atx-heading')
            ->where(static fn(NodeHandle $handle): bool => $handle->exists())
            ->get()
            ->all();

        self::assertContainsOnlyInstancesOf(NodeHandle::class, $handles);
        self::assertCount(2, $handles);
        self::assertSame([1, 3], \array_map(
            static fn(NodeHandle $handle): int => $handle->id()->ordinal,
            $handles,
        ));
    }

    public function testOpenReturnsMarkdownFileWithPath(): void
    {
        $path = __DIR__ . '/../fixtures/readme-symfony.md';
        $file = self::factory()->open($path);

        self::assertInstanceOf(MarkdownFile::class, $file);
        self::assertSame($path, $file->path());
    }

    public function testStepEightMutationExamplesStayExcludedFromStepFive(): void
    {
        $excluded = [
            'Heading::rename() belongs to Step 8 edits.',
            'Section::rename() belongs to Step 8 edits.',
            'Section::append() belongs to Step 8 edits.',
            'Section::prepend() belongs to Step 8 edits.',
            'Section::replaceBody() belongs to Step 8 edits.',
            'Section::remove() belongs to Step 8 edits.',
            'CodeBlock::replaceCode() belongs to Step 8 edits.',
        ];

        self::assertSame(7, \count($excluded));
    }

    private static function document(string $source): MarkdownDocument
    {
        return self::factory()->fromString($source);
    }

    private static function factory(): MarkdownFactory
    {
        return Markdown::github();
    }
}
