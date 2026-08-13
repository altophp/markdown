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

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\FileReadException;
use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\MarkdownFile;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Source\LineEnding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownFactoryTest extends TestCase
{
    /**
     * @param callable(): MarkdownFactory $factory
     */
    #[DataProvider('provideProfileFactories')]
    public function testFacadeCreatesProfileFactory(callable $factory, string $name): void
    {
        $markdown = $factory();

        self::assertSame($name, $markdown->profile()->name());
        self::assertSame($markdown, $markdown->with());
    }

    /**
     * @return iterable<string, array{callable(): MarkdownFactory, string}>
     */
    public static function provideProfileFactories(): iterable
    {
        yield 'commonmark' => [static fn(): MarkdownFactory => Markdown::commonmark(), 'commonmark'];
        yield 'gfm' => [static fn(): MarkdownFactory => Markdown::gfm(), 'gfm'];
        yield 'github' => [static fn(): MarkdownFactory => Markdown::github(), 'github'];
    }

    public function testFromStringReturnsParsedDocumentShell(): void
    {
        $source = "# Title\r\n\r\nBody\r\n";
        $document = Markdown::github()->fromString($source);

        self::assertInstanceOf(MarkdownDocument::class, $document);
        self::assertSame('github', $document->profile()->name());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame($source, $document->model()->source()->bytes);
        self::assertSame(LineEnding::CrLf, $document->model()->source()->dominantEol);
        self::assertFalse($document->model()->source()->hasBom);
        self::assertSame(0, $document->model()->generation());
        self::assertTrue($document->model()->journal()->isEmpty());
        self::assertSame([], $document->model()->journal()->operations());
        self::assertTrue($document->diff()->isEmpty());
    }

    public function testEmptyDocumentHasNoFrontMatter(): void
    {
        self::assertNull(Markdown::github()->fromString('')->frontMatter());
    }

    public function testOpenReturnsFileShellWithPath(): void
    {
        $path = __DIR__ . '/../fixtures/readme-symfony.md';
        $file = Markdown::gfm()->open($path);

        self::assertInstanceOf(MarkdownFile::class, $file);
        self::assertSame($path, $file->path());
        self::assertSame((string) file_get_contents($path), $file->model()->source()->bytes);
        self::assertSame('gfm', $file->profile()->name());
    }

    public function testDocumentModelExposesRootAndReResolving(): void
    {
        $document = Markdown::commonmark()->fromString("# Title\n\nBody\n");
        $model = $document->model();
        $root = $model->root();
        $resolved = $model->node($root->id());

        self::assertInstanceOf(DocumentModel::class, $model);
        self::assertInstanceOf(NodeHandle::class, $root);
        self::assertSame('document', $root->kind()->name);
        self::assertSame(0, $root->id()->generation);
        self::assertSame(0, $root->id()->ordinal);
        self::assertSame(0, $root->range()->startOffset);
        self::assertSame(\strlen("# Title\n\nBody\n"), $root->range()->endOffset);
        self::assertTrue($root->exists());
        self::assertSame($root->id()->generation, $resolved->id()->generation);
        self::assertSame($root->id()->ordinal, $resolved->id()->ordinal);
    }

    public function testHeadingsReadLevelTextAndSourceRangeFromTape(): void
    {
        $source = "# *Title* `Code` &amp;\n\n## Install\n";
        $document = Markdown::github()->fromString($source);
        $title = $document->title();
        $levelTwo = $document->headings(2)->first();

        self::assertNotNull($title);
        self::assertSame(1, $title->level());
        self::assertSame('Title Code &', $title->text());
        self::assertSame(1, $title->id()->ordinal);
        self::assertSame(0, $title->id()->generation);
        self::assertSame(0, $title->range()->startOffset);
        self::assertSame(\strlen('# *Title* `Code` &amp;'), $title->range()->endOffset);

        self::assertNotNull($levelTwo);
        self::assertSame('Install', $levelTwo->text());
        self::assertSame(2, $levelTwo->level());
    }

    public function testHeadingPlainTextPreservesAuthoredInlineHtml(): void
    {
        $title = Markdown::commonmark()->fromString("# before <em> after\n")->title();

        self::assertNotNull($title);
        self::assertSame('before <em> after', $title->text());
    }

    public function testOpenReportsAnUnreadablePath(): void
    {
        $path = __DIR__ . '/missing-document.md';

        $this->expectException(FileReadException::class);
        $this->expectExceptionMessage(\sprintf('Unable to read Markdown file "%s".', $path));

        Markdown::commonmark()->open($path);
    }

    public function testOpenReportsAnUnresolvableParentDirectory(): void
    {
        $path = __DIR__ . '/missing-directory/document.md';

        $this->expectException(FileReadException::class);
        $this->expectExceptionMessage(\sprintf('Unable to read Markdown file "%s".', $path));

        Markdown::commonmark()->open($path);
    }

    public function testOpenRejectsANonRegularFileBeforeReadingIt(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR || !\function_exists('posix_mkfifo')) {
            self::markTestSkipped('FIFO behavior requires POSIX support.');
        }

        $path = \sys_get_temp_dir() . '/alto-markdown-read-' . \bin2hex(\random_bytes(8));
        self::assertTrue(posix_mkfifo($path, 0o600));

        try {
            Markdown::commonmark()->open($path);
            self::fail('Expected the FIFO source to be rejected.');
        } catch (FileReadException $error) {
            self::assertSame(\sprintf('Unable to read regular Markdown file "%s".', $path), $error->getMessage());
            self::assertSame($path, $error->path);
        } finally {
            @unlink($path);
        }
    }

    public function testHeadingIdsAreDeterministicAcrossRepeatedParses(): void
    {
        $source = "# Title\n\n## Install\n";
        $first = Markdown::commonmark()->fromString($source)->headings()->all();
        $second = Markdown::commonmark()->fromString($source)->headings()->all();

        self::assertSame(
            \array_map(static fn(Heading $heading): array => [$heading->id()->generation, $heading->id()->ordinal], $first),
            \array_map(static fn(Heading $heading): array => [$heading->id()->generation, $heading->id()->ordinal], $second),
        );
    }

    public function testFirstHeadingDoesNotWrapUntouchedBlocks(): void
    {
        $document = Markdown::commonmark()->fromString("# First\n\nParagraph.\n\n## Second\n");

        Instrumentation::reset();
        $heading = $document->headings()->first();

        self::assertInstanceOf(Heading::class, $heading);
        self::assertSame('First', $heading->text());
        self::assertSame(1, Instrumentation::$headingHandles);
        self::assertSame(1, Instrumentation::$nodeHandles);
    }
}
