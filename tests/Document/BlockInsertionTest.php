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
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\Block;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\TestCase;

final class BlockInsertionTest extends TestCase
{
    public function testHeadingInsertionReturnsCurrentTypedBlocksAndExactPatch(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nBody.\n");
        $title = $document->headings()->first();
        self::assertNotNull($title);
        $fragment = Markdown::github()
            ->fragment()
            ->raw("## Install\n\nRun.\n")
            ->toFragment();

        $inserted = $title->insertAfter($fragment);
        $blocks = $inserted->all();

        self::assertCount(2, $blocks);
        self::assertInstanceOf(Heading::class, $blocks[0]);
        self::assertSame('Install', $blocks[0]->text());
        self::assertTrue($title->exists());
        self::assertSame(
            "# Title\n\n## Install\n\nRun.\n\nBody.\n",
            $document->toMarkdown(),
        );
        self::assertSame(
            "<h1>Title</h1>\n<h2>Install</h2>\n<p>Run.</p>\n<p>Body.</p>\n",
            $document->toHtml(),
        );
        self::assertFalse($document->diff()->isEmpty());

        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());
        self::assertSame([], $lowered->fallbacks);
        self::assertCount(1, $lowered->patches);
        self::assertSame(8, $lowered->patches[0]->range->startOffset);
        self::assertSame(8, $lowered->patches[0]->range->endOffset);
    }

    public function testGenericTopLevelContainerSupportsCrLfAndBomInsertion(): void
    {
        $document = Markdown::github()->fromString("\xEF\xBB\xBF> Quote\r\n");
        $quote = $document->query()->kind('block-quote')->get()->first();
        self::assertInstanceOf(Block::class, $quote);

        $before = $quote->insertBefore("Intro.\n");
        $after = $quote->insertAfter("After.\n");

        self::assertCount(1, $before);
        self::assertCount(1, $after);
        self::assertSame(
            "\xEF\xBB\xBFIntro.\r\n\r\n> Quote\r\n\r\nAfter.\r\n",
            $document->toMarkdown(),
        );
    }

    public function testInsertionRecognizesBareCarriageReturnBoundaries(): void
    {
        $source = "# One\r\r# Two\r";
        $before = Markdown::github()->fromString($source);
        $beforeHeadings = $before->headings()->all();
        $after = Markdown::github()->fromString($source);
        $afterHeadings = $after->headings()->all();

        $beforeHeadings[1]->insertBefore("Between.\n");
        $afterHeadings[0]->insertAfter("Between.\n");

        self::assertSame("# One\r\rBetween.\r\r# Two\r", $before->toMarkdown());
        self::assertSame("# One\r\rBetween.\r\r# Two\r", $after->toMarkdown());
    }

    public function testNestedBlockInsertionIsExplicitlyRejected(): void
    {
        $document = Markdown::github()->fromString("> Nested.\n");
        $paragraph = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(Block::class, $paragraph);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('limited to top-level blocks');

        $paragraph->insertAfter('Another.');
    }

    public function testNothingCanBeInsertedBeforeExistingFrontMatter(): void
    {
        $document = Markdown::github()->fromString("---\ntitle: Guide\n---\n\n# Guide\n");
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('before an existing front matter');

        $frontMatter->insertBefore('Intro.');
    }

    public function testInsertionCannotCreateFrontMatterAtDocumentStart(): void
    {
        $document = Markdown::github()->fromString("# Guide\n");
        $title = $document->title();
        self::assertNotNull($title);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('must not create front matter');

        $title->insertBefore("---\ntitle: Guide\n---\n");
    }

    public function testInsertionCannotComposeFrontMatterWithTheFollowingBlock(): void
    {
        $source = "key: value\n---\n";
        $document = Markdown::github()->fromString($source);
        $heading = $document->headings()->first();
        self::assertNotNull($heading);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('must not create front matter');

        try {
            $heading->insertBefore('---');
        } finally {
            self::assertSame($source, $document->toMarkdown());
        }
    }

    public function testInsertionAllowsAThematicBreakThatDoesNotCreateFrontMatter(): void
    {
        $document = Markdown::github()->fromString("# Guide\n");
        $title = $document->title();
        self::assertNotNull($title);

        $inserted = $title->insertBefore('---');
        $markdown = $document->toMarkdown();

        self::assertSame('thematic-break', $inserted->first()?->kind()->name);
        self::assertNull(Markdown::github()->fromString($markdown)->frontMatter());
    }

    public function testInsertionCannotCreateFrontMatterAcrossPendingInsertions(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $title = $document->title();
        self::assertNotNull($title);
        $heading = $title->insertBefore("key: value\n---")->first();
        self::assertNotNull($heading);
        $current = $document->toMarkdown();

        try {
            $heading->insertBefore('---');
            self::fail('A same-position insertion at the document start must be rejected.');
        } catch (InvalidMarkdownArgumentException $exception) {
            self::assertStringContainsString('must not create front matter', $exception->getMessage());
        }

        self::assertSame($current, $document->toMarkdown());
    }

    public function testSafeInsertionAtAPendingStartPositionRemainsAllowed(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $title = $document->title();
        self::assertNotNull($title);
        $first = $title->insertBefore('A')->first();
        self::assertNotNull($first);

        $first->insertBefore('B');

        self::assertSame("B\n\nA\n\n# Title\n", $document->toMarkdown());
        self::assertNull(Markdown::github()->fromString($document->toMarkdown())->frontMatter());
    }

    public function testCommonMarkInsertionSkipsFrontMatterCandidateParsing(): void
    {
        $document = Markdown::commonmark()->fromString("# Title\n");
        $title = $document->title();
        self::assertNotNull($title);
        Instrumentation::reset();

        $title->insertBefore('Intro.');

        self::assertSame(1, Instrumentation::$documentWorkspaces);
    }

    public function testDetachedFragmentDoesNotTreatThematicBreakAsFrontMatter(): void
    {
        $document = Markdown::github()->fromString("# Guide\n");
        $title = $document->title();
        self::assertNotNull($title);

        $inserted = $title->insertAfter('---');

        self::assertSame('thematic-break', $inserted->first()?->kind()->name);
        self::assertSame("# Guide\n\n---\n", $document->toMarkdown());
        self::assertSame(
            $document->toHtml(),
            Markdown::github()->fromString($document->toMarkdown())->toHtml(),
        );
    }

    public function testEmptyInsertionIsANoOp(): void
    {
        $document = Markdown::github()->fromString("> Guide\n");
        $nested = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(Block::class, $nested);

        $inserted = $nested->insertAfter("\r\n\n");

        self::assertCount(0, $inserted);
        self::assertTrue($document->model()->journal()->isEmpty());
        self::assertSame("> Guide\n", $document->toMarkdown());
    }

    public function testRepeatedAfterInsertionFallsBackWithoutChangingModelOrder(): void
    {
        $document = Markdown::github()->fromString("# Guide\n");
        $title = $document->title();
        self::assertNotNull($title);

        $title->insertAfter('First.');
        $title->insertAfter('Second.');
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Guide\n\nSecond\\.\n\nFirst\\.\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
        self::assertSame('root', $lowered->fallbacks[0]->kind);
    }
}
