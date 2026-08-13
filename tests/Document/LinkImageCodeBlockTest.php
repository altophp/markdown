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

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\StaleHandleException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\CodeBlock;
use Alto\Markdown\Node\Image;
use Alto\Markdown\Node\Link;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\TestCase;

final class LinkImageCodeBlockTest extends TestCase
{
    public function testLinksAndImagesAreReturnedInDocumentOrder(): void
    {
        $source = <<<'MD'
            [one](https://one.example)

            ![two](/two.png)

            [three](https://three.example)
            MD;
        $document = Markdown::github()->fromString($source);

        $links = $document->links()->all();
        $images = $document->images()->all();

        self::assertContainsOnlyInstancesOf(Link::class, $links);
        self::assertSame('link', $links[0]->kind()->name);
        self::assertSame(['one', 'three'], \array_map(
            static fn(Link $link): string => $link->text(),
            $links,
        ));
        $linkRange = $links[0]->range();
        self::assertSame(
            '[one](https://one.example)',
            substr($source, $linkRange->startOffset, $linkRange->endOffset - $linkRange->startOffset),
        );

        self::assertContainsOnlyInstancesOf(Image::class, $images);
        self::assertSame('image', $images[0]->kind()->name);
        self::assertSame('two', $images[0]->altText());
        $imageRange = $images[0]->range();
        self::assertSame(
            '![two](/two.png)',
            substr($source, $imageRange->startOffset, $imageRange->endOffset - $imageRange->startOffset),
        );
    }

    public function testCodeBlocksIncludeFencedAndIndentedBlocks(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
              ```php
              echo "hello";
              ```

                indented
            MD);

        $blocks = $document->codeBlocks()->all();

        self::assertContainsOnlyInstancesOf(CodeBlock::class, $blocks);
        self::assertCount(2, $blocks);
        self::assertSame('php', $blocks[0]->language());
        self::assertSame("echo \"hello\";\n", $blocks[0]->code());
        self::assertNull($blocks[1]->language());
        self::assertSame("indented\n", $blocks[1]->code());
    }

    public function testCountingCodeBlocksDoesNotParseInlineContent(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            Paragraph with [link](https://example.com).

            ```php
            echo "hello";
            ```
            MD);

        Instrumentation::reset();

        self::assertCount(1, $document->codeBlocks());
        self::assertSame(0, Instrumentation::$inlineParses);
    }

    public function testCodeBlockReplaceCodeReadsBackAndRecordsJournal(): void
    {
        $document = Markdown::github()->fromString("```php\necho \"old\";\n```\n");
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);

        $updated = $block->replaceCode("echo \"new\";\n");

        self::assertFalse($block->exists());
        self::assertSame('php', $updated->language());
        self::assertSame("echo \"new\";\n", $updated->code());
        self::assertSame('replace code block content', $document->model()->journal()->operations()[0]->describe());
        self::assertSame(0, $document->model()->journal()->entries()[0]->affectedRange?->startOffset);

        $this->expectException(StaleHandleException::class);
        $block->code();
    }

    public function testCodeBlockLanguageMutationChangesOnlyTheLanguageToken(): void
    {
        $source = "~~~  php title=demo\r\necho \"ok\";\r\n~~~~\r\n";
        $document = Markdown::github()->fromString($source);
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);

        $updated = $block->setLanguage('tsx');

        self::assertFalse($block->exists());
        self::assertSame('tsx', $updated->language());
        self::assertSame("echo \"ok\";\n", $updated->code());
        self::assertSame("~~~  tsx title=demo\r\necho \"ok\";\r\n~~~~\r\n", $document->toMarkdown());
        self::assertSame('set code block language', $document->model()->journal()->operations()[0]->describe());
        $affectedRange = $document->model()->journal()->entries()[0]->affectedRange;
        self::assertNotNull($affectedRange);
        self::assertSame(5, $affectedRange->startOffset);
        self::assertSame(8, $affectedRange->endOffset);
        self::assertEquals($updated->id(), $document->codeBlocks('tsx')->first()?->id());

        $this->expectException(StaleHandleException::class);
        $block->language();
    }

    public function testCodeBlockLanguageMutationInsertsMissingLanguageAfterMarker(): void
    {
        $document = Markdown::github()->fromString("```  \ncode\n```\n");
        $block = $document->codeBlocks()->first();
        self::assertNotNull($block);

        $updated = $block->setLanguage('php');

        self::assertSame('php', $updated->language());
        self::assertSame("```php  \ncode\n```\n", $document->toMarkdown());
        $affectedRange = $document->model()->journal()->entries()[0]->affectedRange;
        self::assertNotNull($affectedRange);
        self::assertSame(3, $affectedRange->startOffset);
        self::assertSame(3, $affectedRange->endOffset);
    }

    public function testCodeBlockLanguageMutationPreservesBomAndContainerPrefixes(): void
    {
        $source = "\xEF\xBB\xBF> ~~~ php meta\r\n> code\r\n> ~~~\r\n";
        $document = Markdown::github()->fromString($source);
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block->setLanguage('tsx');

        self::assertSame("\xEF\xBB\xBF> ~~~ tsx meta\r\n> code\r\n> ~~~\r\n", $document->toMarkdown());
    }

    public function testSettingCurrentCodeBlockLanguageIsANoOp(): void
    {
        $document = Markdown::github()->fromString("```php\ncode\n```\n");
        $block = $document->codeBlocks('php')->first();
        self::assertNotNull($block);

        $updated = $block->setLanguage('php');

        self::assertSame($block, $updated);
        self::assertTrue($block->exists());
        self::assertTrue($document->model()->journal()->isEmpty());
    }

    public function testCodeBlockLanguageRejectsInvalidTokens(): void
    {
        $document = Markdown::github()->fromString("```\ncode\n```\n");
        $block = $document->codeBlocks()->first();
        self::assertNotNull($block);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Code block language must be a non-empty token');

        $block->setLanguage('not valid');
    }

    public function testCodeBlockLanguageRejectsIndentedCodeBlocks(): void
    {
        $document = Markdown::github()->fromString("    code\n");
        $block = $document->codeBlocks()->first();
        self::assertNotNull($block);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('unsupported kind');

        $block->setLanguage('php');
    }
}
