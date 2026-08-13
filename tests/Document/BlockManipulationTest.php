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
use Alto\Markdown\Node\Block;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Operation\BlockWrapper;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockManipulationTest extends TestCase
{
    public function testMoveAfterPreservesOriginalBytesWithTwoDisjointPatches(): void
    {
        $source = "\xEF\xBB\xBF# Title\r\n\r\n##   First ###\r\n\r\nMiddle  \r\n\r\n## Second\r\n";
        $document = Markdown::github()->fromString($source);
        $first = $document->headings(2)->all()[0];
        $second = $document->headings(2)->all()[1];

        $returned = $first->moveAfter($second);
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame($first, $returned);
        self::assertTrue($first->exists());
        self::assertTrue($second->exists());
        self::assertSame(
            "\xEF\xBB\xBF# Title\r\n\r\nMiddle  \r\n\r\n## Second\r\n\r\n##   First ###\r\n",
            $lowered->bytes,
        );
        self::assertCount(2, $lowered->patches);
        self::assertSame([], $lowered->fallbacks);
        self::assertStringEndsWith("##   First ###\r\n", $lowered->patches[1]->replacement);
    }

    public function testMoveBeforePreservesBareCarriageReturns(): void
    {
        $source = "# Title\r\rFirst.\r\rSecond.\r";
        $document = Markdown::github()->fromString($source);
        $paragraphs = self::blocks($document->query()->kind('paragraph')->get()->all());

        $paragraphs[1]->moveBefore($paragraphs[0]);
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Title\r\rSecond.\r\rFirst.\r", $lowered->bytes);
        self::assertSame([], $lowered->fallbacks);
        self::assertCount(2, $lowered->patches);
    }

    public function testMovingTheFirstBlockConsumesOnlyItsSeparator(): void
    {
        $document = Markdown::github()->fromString("First.\n\nSecond.\n\nThird.\n");
        $paragraphs = self::blocks($document->query()->kind('paragraph')->get()->all());

        $paragraphs[0]->moveAfter($paragraphs[2]);
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("Second.\n\nThird.\n\nFirst.\n", $lowered->bytes);
        self::assertSame([], $lowered->fallbacks);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'CR' => ["\r"];
    }

    #[DataProvider('lineEndings')]
    public function testMovesWithoutBlankLinesNeverConsumeANeighbor(string $eol): void
    {
        $source = '# A' . $eol . '# B' . $eol . '# C' . $eol;

        $first = Markdown::github()->fromString($source);
        $firstHeadings = $first->headings()->all();
        $firstHeadings[0]->moveAfter($firstHeadings[2]);
        self::assertSame('# B' . $eol . '# C' . $eol . $eol . '# A' . $eol, $first->toMarkdown());

        $last = Markdown::github()->fromString($source);
        $lastHeadings = $last->headings()->all();
        $lastHeadings[2]->moveBefore($lastHeadings[0]);
        self::assertSame('# C' . $eol . $eol . '# A' . $eol . '# B' . $eol, $last->toMarkdown());

        $middle = Markdown::github()->fromString($source);
        $middleHeadings = $middle->headings()->all();
        $middleHeadings[1]->moveAfter($middleHeadings[2]);
        self::assertSame('# A' . $eol . '# C' . $eol . $eol . '# B' . $eol, $middle->toMarkdown());
    }

    public function testAdjacentAndSelfMovesAreNoOps(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nFirst.\n\nSecond.\n");
        $paragraphs = self::blocks($document->query()->kind('paragraph')->get()->all());
        $generation = $document->model()->generation();

        self::assertSame($paragraphs[0], $paragraphs[0]->moveBefore($paragraphs[1]));
        self::assertSame($paragraphs[1], $paragraphs[1]->moveAfter($paragraphs[0]));
        self::assertSame($paragraphs[0], $paragraphs[0]->moveBefore($paragraphs[0]));
        self::assertSame($generation, $document->model()->generation());
        self::assertTrue($document->model()->journal()->isEmpty());
    }

    public function testMoveRejectsAnotherDocumentAndAStaleDestination(): void
    {
        $document = Markdown::github()->fromString("# One\n\nFirst.\n\nSecond.\n");
        $other = Markdown::github()->fromString("# Other\n");
        $paragraphs = self::blocks($document->query()->kind('paragraph')->get()->all());
        $otherTitle = $other->title();
        self::assertNotNull($otherTitle);

        try {
            $paragraphs[0]->moveAfter($otherTitle);
            self::fail('Expected a cross-document destination to be rejected.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame('A block destination must belong to the same document.', $error->getMessage());
        }

        $paragraphs[1]->replaceWith('Replacement.');

        $this->expectException(StaleHandleException::class);
        $paragraphs[0]->moveAfter($paragraphs[1]);
    }

    public function testNestedBlocksAreRejectedExplicitly(): void
    {
        $document = Markdown::github()->fromString("> Nested.\n\nOutside.\n");
        $nested = $document->query()->kind('paragraph')->get()->all()[0];
        $outside = $document->query()->kind('paragraph')->get()->all()[1];
        self::assertInstanceOf(Block::class, $nested);
        self::assertInstanceOf(Block::class, $outside);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('limited to top-level blocks');

        $nested->moveAfter($outside);
    }

    public function testFrontMatterUsesOnlyItsDedicatedMutationApi(): void
    {
        $document = Markdown::github()->fromString("---\ntitle: Guide\n---\n\n# Guide\n");
        $frontMatter = $document->frontMatter();
        $title = $document->title();
        self::assertNotNull($frontMatter);
        self::assertNotNull($title);

        try {
            $frontMatter->cloneAfter($title);
            self::fail('Expected general front matter manipulation to be rejected.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertStringContainsString('dedicated front matter API', $error->getMessage());
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('before an existing front matter');

        $title->moveBefore($frontMatter);
    }

    public function testMoveAndCloneRejectFrontMatterCreatedWithANeighbor(): void
    {
        $source = "key: value\n---\n\nBody\n\n---\n";

        foreach (['move', 'clone'] as $operation) {
            $document = Markdown::github()->fromString($source);
            $heading = $document->headings()->first();
            $thematic = $document->query()->kind('thematic-break')->get()->first();
            self::assertNotNull($heading);
            self::assertInstanceOf(Block::class, $thematic);

            try {
                match ($operation) {
                    'move' => $thematic->moveBefore($heading),
                    'clone' => $thematic->cloneBefore($heading),
                };
                self::fail('Expected composed front matter to be rejected.');
            } catch (InvalidMarkdownArgumentException $error) {
                self::assertStringContainsString('must not create front matter', $error->getMessage());
            }

            self::assertSame($source, $document->toMarkdown());
            self::assertTrue($document->model()->journal()->isEmpty());
        }
    }

    public function testAllowedMoveToStartMatchesAReparsedDocument(): void
    {
        $document = Markdown::github()->fromString("# Guide\n\nBody\n\n---\n");
        $title = $document->title();
        $thematic = $document->query()->kind('thematic-break')->get()->first();
        self::assertNotNull($title);
        self::assertInstanceOf(Block::class, $thematic);

        $thematic->moveBefore($title);
        $markdown = $document->toMarkdown();
        $reparsed = Markdown::github()->fromString($markdown);

        self::assertNull($reparsed->frontMatter());
        self::assertSame($document->toHtml(), $reparsed->toHtml());
    }

    public function testCloneReturnsOneTypedBlockAndKeepsTheSourceHandle(): void
    {
        $source = "\xEF\xBB\xBF# Title\r\n\r\n##   Copy Me ###\r\n\r\nEnd.\r\n";
        $document = Markdown::github()->fromString($source);
        $heading = $document->headings(2)->first();
        $end = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(Heading::class, $heading);
        self::assertInstanceOf(Block::class, $end);

        $clone = $heading->cloneAfter($end);
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertInstanceOf(Heading::class, $clone);
        self::assertSame('Copy Me', $clone->text());
        self::assertTrue($heading->exists());
        self::assertSame(
            $source . "\r\n##   Copy Me ###\r\n",
            $lowered->bytes,
        );
        self::assertSame([], $lowered->fallbacks);
    }

    public function testCloneBeforeCanInsertAtTheBomLogicalStart(): void
    {
        $source = "\xEF\xBB\xBF# Title\r\n\r\nCopy.\r\n";
        $document = Markdown::github()->fromString($source);
        $title = $document->title();
        $paragraph = $document->query()->kind('paragraph')->get()->first();
        self::assertNotNull($title);
        self::assertInstanceOf(Block::class, $paragraph);

        $clone = $paragraph->cloneBefore($title);

        self::assertSame('paragraph', $clone->kind()->name);
        self::assertSame(
            "\xEF\xBB\xBFCopy.\r\n\r\n# Title\r\n\r\nCopy.\r\n",
            $document->toMarkdown(),
        );
    }

    public function testCloneAfterCanInsertAtAMiddleCrLfBoundary(): void
    {
        $document = Markdown::github()->fromString("# Title\r\n\r\nBody.\r\n");
        $title = $document->title();
        self::assertNotNull($title);

        $clone = $title->cloneAfter($title);

        self::assertInstanceOf(Heading::class, $clone);
        self::assertSame('Title', $clone->text());
        self::assertSame("# Title\r\n\r\n# Title\r\n\r\nBody.\r\n", $document->toMarkdown());
    }

    public function testCloneUsesTheCurrentEditedBlock(): void
    {
        $document = Markdown::github()->fromString("# Title\n\n## Old\n\nEnd.\n");
        $heading = $document->headings(2)->first();
        $end = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(Heading::class, $heading);
        self::assertInstanceOf(Block::class, $end);

        $heading->rename('Current');
        $clone = $heading->cloneAfter($end);

        self::assertInstanceOf(Heading::class, $clone);
        self::assertSame('Current', $clone->text());
        self::assertSame("# Title\n\n## Current\n\nEnd.\n\n## Current\n", $document->toMarkdown());
    }

    public function testCloneUsesEditsInsideAContainer(): void
    {
        $document = Markdown::github()->fromString("> [docs](/old)\n\nEnd.\n");
        $quote = $document->query()->kind('block-quote')->get()->first();
        $end = $document->query()->kind('paragraph')->get()->all()[1];
        $link = $document->links()->first();
        self::assertInstanceOf(Block::class, $quote);
        self::assertInstanceOf(Block::class, $end);
        self::assertNotNull($link);

        $link->setDestination('/new');
        $quote->cloneAfter($end);

        self::assertSame(2, substr_count($document->toHtml(), 'href="/new"'));
        self::assertStringNotContainsString('/old', $document->toMarkdown());
    }

    public function testCloneUsesEditsInsideAGeneratedContainer(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $title = $document->title();
        self::assertNotNull($title);
        $quote = $title->insertBefore('> [docs](/old)')->first();
        self::assertInstanceOf(Block::class, $quote);

        $quote->cloneAfter($title);
        $link = $document->links()->first();
        self::assertNotNull($link);
        $link->setDestination('/new');
        $quote->cloneAfter($title);

        self::assertSame(2, substr_count($document->toHtml(), 'href="/new"'));
        self::assertSame(1, substr_count($document->toHtml(), 'href="/old"'));
    }

    public function testCommonMarkMoveAndReplaceSkipFrontMatterCandidateParsing(): void
    {
        $document = Markdown::commonmark()->fromString("# Title\n\nBody.\n");
        $title = $document->title();
        $body = $document->query()->kind('paragraph')->get()->first();
        self::assertNotNull($title);
        self::assertInstanceOf(Block::class, $body);
        Instrumentation::reset();

        $title->moveAfter($body);
        $body->replaceWith('Current.');

        self::assertSame(1, Instrumentation::$documentWorkspaces);
    }

    public function testRepeatedCloneAtOneOffsetFallsBackWithoutLosingOrder(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nCopy.\n\nEnd.\n");
        $paragraphs = self::blocks($document->query()->kind('paragraph')->get()->all());

        $paragraphs[0]->cloneAfter($paragraphs[1]);
        $paragraphs[0]->cloneAfter($paragraphs[1]);
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Title\n\nCopy\\.\n\nEnd\\.\n\nCopy\\.\n\nCopy\\.\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
    }

    public function testReplaceWithReturnsTypedBlocksAndStalesTheSource(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nOld.\n\nEnd.\n");
        $old = $document->query()->kind('paragraph')->get()->all()[0];
        self::assertInstanceOf(Block::class, $old);

        $replacement = $old->replaceWith("## New\n\nBody.");

        self::assertCount(2, $replacement);
        self::assertInstanceOf(Heading::class, $replacement->first());
        self::assertFalse($old->exists());
        self::assertSame("# Title\n\n## New\n\nBody.\n\nEnd.\n", $document->toMarkdown());

        $this->expectException(StaleHandleException::class);
        $old->replaceWith('Again.');
    }

    public function testReplaceWithEmptyRemovesTheBlock(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nRemove.\n\nKeep.\n");
        $remove = $document->query()->kind('paragraph')->get()->all()[0];
        self::assertInstanceOf(Block::class, $remove);

        $replacement = $remove->replaceWith('');

        self::assertCount(0, $replacement);
        self::assertFalse($remove->exists());
        self::assertSame("# Title\n\nKeep.\n", $document->toMarkdown());
    }

    public function testReplaceAcceptsAFragmentAndPreservesCrWithoutFinalEol(): void
    {
        $document = Markdown::github()->fromString("# Title\r\rOld.");
        $old = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(Block::class, $old);
        $fragment = Markdown::github()->fragment()->paragraph('New.')->toFragment();

        $replacement = $old->replaceWith($fragment);

        self::assertCount(1, $replacement);
        self::assertSame("# Title\r\rNew\\.", $document->toMarkdown());
    }

    public function testReplaceCannotOpenFrontMatterAtDocumentStart(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $title = $document->title();
        self::assertNotNull($title);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('must not create front matter');

        $title->replaceWith("---\ntitle: Other\n---");
    }

    public function testReplaceRejectsFrontMatterCreatedWithTheFollowingBlock(): void
    {
        $source = "Old\n\nkey: value\n---\n";
        $document = Markdown::github()->fromString($source);
        $old = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(Block::class, $old);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('must not create front matter');

        try {
            $old->replaceWith('---');
        } finally {
            self::assertSame($source, $document->toMarkdown());
        }
    }

    /**
     * @return iterable<string, array{BlockWrapper, string, string}>
     */
    public static function wrappers(): iterable
    {
        yield 'quote' => [BlockWrapper::quote(), "> First.\n> Second.\n", 'block-quote'];
        yield 'bullet' => [BlockWrapper::bullet('*'), "* First.\n  Second.\n", 'list'];
        yield 'ordered' => [BlockWrapper::ordered(3, ')'), "3) First.\n   Second.\n", 'list'];
    }

    #[DataProvider('wrappers')]
    public function testWrapCreatesExactlyOneSafeContainer(
        BlockWrapper $wrapper,
        string $expected,
        string $kind,
    ): void {
        $document = Markdown::github()->fromString("First.\nSecond.\n");
        $paragraph = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(Block::class, $paragraph);

        $container = $paragraph->wrap($wrapper);

        self::assertSame($kind, $container->kind()->name);
        self::assertFalse($paragraph->exists());
        self::assertSame($expected, $document->toMarkdown());
    }

    public function testWrapperArgumentsAreValidated(): void
    {
        foreach (
            [
                static fn(): BlockWrapper => BlockWrapper::bullet('x'),
                static fn(): BlockWrapper => BlockWrapper::ordered(-1),
                static fn(): BlockWrapper => BlockWrapper::ordered(1, ':'),
            ] as $create
        ) {
            try {
                $create();
                self::fail('Expected an invalid block wrapper argument.');
            } catch (InvalidMarkdownArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWrapperDefaultsBoundsAndEmptyInput(): void
    {
        self::assertSame("> Text\n", BlockWrapper::quote()->apply("Text\n"));
        self::assertSame("- Text\n", BlockWrapper::bullet()->apply("Text\n"));
        self::assertSame("1. Text\n", BlockWrapper::ordered()->apply("Text\n"));
        self::assertSame(
            "999999999) Text\n           More\n",
            BlockWrapper::ordered(999_999_999, ')')->apply("Text\nMore\n"),
        );

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('requires non-empty Markdown');

        BlockWrapper::quote()->apply('');
    }

    public function testWrapPreservesBomCrLfBlankLinesAndFencedCode(): void
    {
        $source = "\xEF\xBB\xBF```php\r\necho 1;\r\n\r\necho 2;\r\n```\r\n";
        $document = Markdown::github()->fromString($source);
        $code = $document->codeBlocks()->first();
        self::assertNotNull($code);

        $container = $code->wrap(BlockWrapper::quote());

        self::assertSame('block-quote', $container->kind()->name);
        self::assertSame(
            "\xEF\xBB\xBF> ```php\r\n> echo 1;\r\n> \r\n> echo 2;\r\n> ```\r\n",
            $document->toMarkdown(),
        );
    }

    public function testWrapPreservesBareCrLineBoundaries(): void
    {
        $document = Markdown::github()->fromString("First.\rSecond.\r");
        $paragraph = $document->query()->kind('paragraph')->get()->first();
        self::assertInstanceOf(Block::class, $paragraph);

        $paragraph->wrap(BlockWrapper::bullet('+'));

        self::assertSame("+ First.\r  Second.\r", $document->toMarkdown());
    }

    public function testMoveThenEditFallsBackWithoutLosingEitherChange(): void
    {
        $document = Markdown::github()->fromString("# Title\n\n## First\n\n## Second\n");
        $first = $document->headings(2)->all()[0];
        $second = $document->headings(2)->all()[1];

        $first->moveAfter($second)->rename('Moved');
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Title\n\n## Second\n\n## Moved\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
        self::assertSame('root', $lowered->fallbacks[0]->kind);
    }

    public function testInsertThenMoveAtTheSameOffsetKeepsModelOrder(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nMove.\n\nEnd.\n");
        $title = $document->title();
        $paragraphs = self::blocks($document->query()->kind('paragraph')->get()->all());
        self::assertNotNull($title);

        $title->insertAfter('Inserted.');
        $paragraphs[1]->moveAfter($title);
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Title\n\nEnd\\.\n\nInserted\\.\n\nMove\\.\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
    }

    public function testMoveThenInsertAtTheSameOffsetKeepsModelOrder(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nMove.\n\nEnd.\n");
        $title = $document->title();
        $paragraphs = self::blocks($document->query()->kind('paragraph')->get()->all());
        self::assertNotNull($title);

        $paragraphs[1]->moveAfter($title);
        $title->insertAfter('Inserted.');
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Title\n\nInserted\\.\n\nEnd\\.\n\nMove\\.\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
    }

    public function testMoveThenCloneAtTheSameOffsetKeepsModelOrder(): void
    {
        $document = Markdown::github()->fromString("# Title\n\nMove.\n\nEnd.\n");
        $title = $document->title();
        $paragraphs = self::blocks($document->query()->kind('paragraph')->get()->all());
        self::assertNotNull($title);

        $paragraphs[1]->moveAfter($title);
        $paragraphs[0]->cloneAfter($title);
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Title\n\nMove\\.\n\nEnd\\.\n\nMove\\.\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
    }

    public function testAnInsertedBlockCanBeMovedSafely(): void
    {
        $document = Markdown::github()->fromString("# Title\n\n## End\n");
        $title = $document->title();
        $end = $document->headings(2)->first();
        self::assertNotNull($title);
        self::assertNotNull($end);
        $inserted = $title->insertAfter('## Added')->first();
        self::assertInstanceOf(Block::class, $inserted);

        $inserted->moveAfter($end);
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("# Title\n\n## End\n\n## Added\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
    }

    public function testAnInsertedBlockCanBeClonedSafely(): void
    {
        $document = Markdown::github()->fromString("# Title\n\n## End\n");
        $title = $document->title();
        $end = $document->headings(2)->first();
        self::assertNotNull($title);
        self::assertNotNull($end);
        $inserted = $title->insertAfter('## Added')->first();
        self::assertInstanceOf(Block::class, $inserted);

        $clone = $inserted->cloneAfter($end);
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertInstanceOf(Heading::class, $clone);
        self::assertSame("# Title\n\n## Added\n\n## End\n\n## Added\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
    }

    public function testAnInsertedBlockCanBeReplacedSafely(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $title = $document->title();
        self::assertNotNull($title);
        $inserted = $title->insertAfter('## Added')->first();
        self::assertInstanceOf(Block::class, $inserted);

        $replacement = $inserted->replaceWith('## Changed');
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertInstanceOf(Heading::class, $replacement->first());
        self::assertSame("# Title\n\n## Changed\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
    }

    public function testAnInsertedBlockCanBeWrappedSafely(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $title = $document->title();
        self::assertNotNull($title);
        $inserted = $title->insertAfter('## Added')->first();
        self::assertInstanceOf(Block::class, $inserted);

        $container = $inserted->wrap(BlockWrapper::quote());
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame('block-quote', $container->kind()->name);
        self::assertSame("# Title\n\n> ## Added\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
    }

    /**
     * @param list<NodeHandle> $handles
     *
     * @return list<Block>
     */
    private static function blocks(array $handles): array
    {
        $blocks = [];

        foreach ($handles as $handle) {
            if (!$handle instanceof Block) {
                self::fail('Expected a block handle.');
            }

            $blocks[] = $handle;
        }

        return $blocks;
    }
}
