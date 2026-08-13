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

use Alto\Markdown\Exception\FileConflictException;
use Alto\Markdown\Exception\FileWriteException;
use Alto\Markdown\Exception\SourceSizeLimitException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\SaveOptions;
use Alto\Markdown\Operation\SymlinkPolicy;
use Alto\Markdown\Parser\ParseOptions;
use PHPUnit\Framework\TestCase;

final class MarkdownFileSaveTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    /**
     * @var list<string>
     */
    private array $directories = [];

    public function testSaveSkipsWriteWhenJournalIsEmpty(): void
    {
        $path = $this->writeTempFile("# Title\n");
        $file = Markdown::github()->open($path);
        $mtime = filemtime($path);

        $file->save();

        self::assertSame("# Title\n", file_get_contents($path));
        self::assertSame($mtime, filemtime($path));
        self::assertTrue($file->model()->journal()->isEmpty());
    }

    public function testSaveWritesLoweredBytesClearsJournalAndRebasesModel(): void
    {
        $path = $this->writeTempFile("```php\necho \"old\";\n```\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block->replaceCode("echo \"new\";\n");
        $file->save();

        self::assertSame("```php\necho \"new\";\n```\n", file_get_contents($path));
        self::assertTrue($file->model()->journal()->isEmpty());
        self::assertTrue($file->diff()->isEmpty());
        self::assertSame(file_get_contents($path), $file->model()->source()->bytes);
    }

    public function testSavePersistsBlockInsertionAndRetiresOldHandles(): void
    {
        $path = $this->writeTempFile("# Guide\r\n");
        $file = Markdown::github()->open($path);
        $title = $file->title();
        self::assertNotNull($title);

        $inserted = $title->insertAfter("Body.\n")->first();
        self::assertNotNull($inserted);
        $file->save();

        self::assertSame("# Guide\r\n\r\nBody.\r\n", file_get_contents($path));
        self::assertFalse($title->exists());
        self::assertFalse($inserted->exists());
        self::assertCount(1, $file->query()->kind('paragraph')->get());
        self::assertTrue($file->diff()->isEmpty());
    }

    public function testCrLfSourceStaysCrLfByDefault(): void
    {
        $path = $this->writeTempFile("```php\r\necho \"old\";\r\n```\r\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block->replaceCode("echo \"new\";\n");
        $file->save();

        self::assertSame("```php\r\necho \"new\";\r\n```\r\n", file_get_contents($path));
    }

    public function testPreserveEolFalseNormalizesToDominantEol(): void
    {
        $path = $this->writeTempFile("# Title\r\n\n```php\nold();\n```\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block->replaceCode("new();\n");
        $file->save(new SaveOptions(preserveEol: false));

        self::assertSame("# Title\n\n```php\nnew();\n```\n", file_get_contents($path));
    }

    public function testBomIsPreservedByDefaultAndCanBeRemoved(): void
    {
        $path = $this->writeTempFile("\xEF\xBB\xBF```php\necho \"old\";\n```\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block->replaceCode("echo \"new\";\n");
        $file->save();

        self::assertStringStartsWith("\xEF\xBB\xBF", (string) file_get_contents($path));

        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("echo \"newer\";\n");
        $file->save(new SaveOptions(preserveBom: false));

        self::assertStringStartsNotWith("\xEF\xBB\xBF", (string) file_get_contents($path));
    }

    public function testSaveAsUsesSamePreservationDefaultsAndUpdatesPath(): void
    {
        $source = $this->writeTempFile("```php\r\necho \"old\";\r\n```\r\n");
        $target = $this->tempPath();
        $file = Markdown::github()->open($source);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block->replaceCode("echo \"new\";\n");
        $file->saveAs($target);

        self::assertSame($target, $file->path());
        self::assertSame("```php\r\necho \"new\";\r\n```\r\n", file_get_contents($target));
        self::assertTrue($file->model()->journal()->isEmpty());
    }

    public function testRelativeOpenRemainsAnchoredAfterWorkingDirectoryChanges(): void
    {
        $firstDirectory = $this->tempDirectory();
        $secondDirectory = $this->tempDirectory();
        $firstPath = $firstDirectory . '/guide.md';
        $secondPath = $secondDirectory . '/guide.md';
        file_put_contents($firstPath, "# Guide\n");
        file_put_contents($secondPath, "# Guide\n");
        $this->paths[] = $firstPath;
        $this->paths[] = $secondPath;
        $workingDirectory = getcwd();
        self::assertIsString($workingDirectory);

        try {
            self::assertTrue(chdir($firstDirectory));
            $file = Markdown::github()->open('guide.md');
            $file->section('Guide')->append("First.\n");

            self::assertTrue(chdir($secondDirectory));
            $file->save(new SaveOptions(compareBeforeWrite: true));
        } finally {
            chdir($workingDirectory);
        }

        self::assertSame('guide.md', $file->path());
        self::assertSame("# Guide\n\nFirst.\n\n", file_get_contents($firstPath));
        self::assertSame("# Guide\n", file_get_contents($secondPath));
    }

    public function testRelativeSaveAsRemainsAnchoredForLaterSaves(): void
    {
        $source = $this->writeTempFile("# Guide\n");
        $firstDirectory = $this->tempDirectory();
        $secondDirectory = $this->tempDirectory();
        $firstPath = $firstDirectory . '/copy.md';
        $secondPath = $secondDirectory . '/copy.md';
        $this->paths[] = $firstPath;
        $this->paths[] = $secondPath;
        $workingDirectory = getcwd();
        self::assertIsString($workingDirectory);
        $file = Markdown::github()->open($source);

        try {
            self::assertTrue(chdir($firstDirectory));
            $file->saveAs('copy.md', new SaveOptions(compareBeforeWrite: true));
            $file->section('Guide')->append("Later.\n");

            self::assertTrue(chdir($secondDirectory));
            $file->save(new SaveOptions(compareBeforeWrite: true));
        } finally {
            chdir($workingDirectory);
        }

        self::assertSame('copy.md', $file->path());
        self::assertSame("# Guide\n\nLater.\n\n", file_get_contents($firstPath));
        self::assertFileDoesNotExist($secondPath);
    }

    public function testSaveAsCopiesAnUnchangedDocument(): void
    {
        $source = $this->writeTempFile("# Guide\n");
        $target = $this->tempPath();
        $file = Markdown::github()->open($source);

        $file->saveAs($target);

        self::assertSame("# Guide\n", file_get_contents($target));
        self::assertSame($target, $file->path());
        self::assertTrue($file->model()->journal()->isEmpty());
    }

    public function testNonAtomicSaveReportsAnUnwritableTarget(): void
    {
        $source = $this->writeTempFile("# Guide\n");
        $file = Markdown::github()->open($source);
        $file->section('Guide')->append("Body.\n");

        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage(\sprintf('Refusing to write non-regular Markdown file "%s".', \sys_get_temp_dir()));

        $file->saveAs(\sys_get_temp_dir(), new SaveOptions(atomic: false));
    }

    public function testSaveAsRejectsAFifoBeforeAtomicReplacement(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR || !\function_exists('posix_mkfifo')) {
            self::markTestSkipped('FIFO behavior requires POSIX support.');
        }

        $source = $this->writeTempFile("# Guide\n");
        $fifo = $this->tempPath();
        self::assertTrue(posix_mkfifo($fifo, 0o600));
        $file = Markdown::github()->open($source);

        try {
            $file->saveAs($fifo);
            self::fail('Expected the FIFO target to be rejected.');
        } catch (FileWriteException $error) {
            self::assertSame(
                \sprintf('Refusing to write non-regular Markdown file "%s".', $fifo),
                $error->getMessage(),
            );
            self::assertSame($fifo, $error->path);
            $metadata = lstat($fifo);

            if (false === $metadata) {
                self::fail('Expected the FIFO target to remain present.');
            }

            self::assertSame(0o010000, $metadata['mode'] & 0o170000);
        }
    }

    public function testFailedAtomicWriteLeavesOriginalFileAndJournalIntact(): void
    {
        $path = $this->writeTempFile("```php\necho \"old\";\n```\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("echo \"new\";\n");

        $this->expectException(\RuntimeException::class);

        try {
            $file->saveAs(\dirname($path) . '/missing/out.md');
        } finally {
            self::assertSame("```php\necho \"old\";\n```\n", file_get_contents($path));
            self::assertFalse($file->model()->journal()->isEmpty());
        }
    }

    public function testAtomicSavePreservesExistingFilePermissions(): void
    {
        $path = $this->writeTempFile("#  Title\n");
        chmod($path, 0o644);
        $file = Markdown::github()->open($path);

        $file->format();
        $file->save();

        clearstatcache(true, $path);
        self::assertSame("# Title\n", file_get_contents($path));
        self::assertSame(0o644, fileperms($path) & 0o777);
    }

    public function testAtomicSaveAsCreatesAFileUsingTheProcessUmask(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX modes are not portable to Windows.');
        }

        $source = $this->writeTempFile("# Guide\n");
        $target = $this->tempPath();
        $expectedMode = 0o666 & ~umask();
        $file = Markdown::github()->open($source);

        $file->saveAs($target);

        clearstatcache(true, $target);
        self::assertSame($expectedMode, fileperms($target) & 0o777);
    }

    public function testSaveRejectsSymlinkTargetsByDefault(): void
    {
        $target = $this->writeTempFile("```php\nold();\n```\n");
        $link = $this->tempPath();
        $this->createSymlink($target, $link);
        $file = Markdown::github()->open($link);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("new();\n");

        try {
            $file->save();
            self::fail('Expected the default symlink policy to reject the write.');
        } catch (FileWriteException $error) {
            self::assertSame(\sprintf('Refusing to write Markdown symlink "%s".', $link), $error->getMessage());
            self::assertSame($link, $error->path);
            self::assertTrue(is_link($link));
            self::assertSame("```php\nold();\n```\n", file_get_contents($target));
            self::assertFalse($file->model()->journal()->isEmpty());
        }
    }

    public function testFollowSymlinkWritesResolvedTargetAndPreservesLinkAndMode(): void
    {
        $target = $this->writeTempFile("```php\nold();\n```\n");
        chmod($target, 0o640);
        $link = $this->tempPath();
        $this->createSymlink($target, $link);
        $file = Markdown::github()->open($link);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("new();\n");

        $file->save(new SaveOptions(symlinks: SymlinkPolicy::Follow));

        clearstatcache(true, $target);
        self::assertTrue(is_link($link));
        self::assertSame(realpath($target), realpath($link));
        self::assertSame("```php\nnew();\n```\n", file_get_contents($target));
        self::assertSame(0o640, fileperms($target) & 0o777);
        self::assertTrue($file->model()->journal()->isEmpty());
    }

    public function testFollowSymlinkNonAtomicWriteKeepsTheTargetInode(): void
    {
        $target = $this->writeTempFile("```php\nold();\n```\n");
        $link = $this->tempPath();
        $this->createSymlink($target, $link);
        $inode = fileinode($target);
        $file = Markdown::github()->open($link);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("new();\n");

        $file->save(new SaveOptions(atomic: false, symlinks: SymlinkPolicy::Follow));

        clearstatcache(true, $target);
        self::assertSame($inode, fileinode($target));
        self::assertTrue(is_link($link));
        self::assertSame("```php\nnew();\n```\n", file_get_contents($target));
    }

    public function testFollowSymlinkRejectsADanglingTarget(): void
    {
        $source = $this->writeTempFile("# Guide\n");
        $link = $this->tempPath();
        $missing = $this->tempPath();
        $this->createSymlink($missing, $link);
        $file = Markdown::github()->open($source);

        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage(\sprintf('Unable to resolve Markdown symlink "%s" to a regular file.', $link));

        $file->saveAs($link, new SaveOptions(symlinks: SymlinkPolicy::Follow));
    }

    public function testFollowSymlinkRejectsADirectoryTarget(): void
    {
        $source = $this->writeTempFile("# Guide\n");
        $directory = $this->tempDirectory();
        $link = $this->tempPath();
        $this->createSymlink($directory, $link);
        $file = Markdown::github()->open($source);

        $this->expectException(FileWriteException::class);
        $this->expectExceptionMessage(\sprintf('Unable to resolve Markdown symlink "%s" to a regular file.', $link));

        $file->saveAs($link, new SaveOptions(symlinks: SymlinkPolicy::Follow));
    }

    public function testCompareBeforeWriteRejectsAnExternalChangeAndPreservesPendingWork(): void
    {
        $path = $this->writeTempFile("```php\nold();\n```\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("local();\n");
        $diff = $file->diff()->toUnifiedString();
        file_put_contents($path, "```php\nexternal();\n```\n");

        try {
            $file->save(new SaveOptions(compareBeforeWrite: true));
            self::fail('Expected the external change to prevent saving.');
        } catch (FileConflictException $error) {
            self::assertSame($path, $error->path);
            self::assertSame(
                \sprintf('Markdown file "%s" changed after it was opened.', $path),
                $error->getMessage(),
            );
            self::assertSame("```php\nexternal();\n```\n", file_get_contents($path));
            self::assertFalse($file->model()->journal()->isEmpty());
            self::assertSame($diff, $file->diff()->toUnifiedString());
        }
    }

    public function testCompareBeforeWriteCanBeDisabledExplicitly(): void
    {
        $path = $this->writeTempFile("```php\nold();\n```\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("local();\n");
        file_put_contents($path, "```php\nexternal();\n```\n");

        $file->save(new SaveOptions(compareBeforeWrite: false));

        self::assertSame("```php\nlocal();\n```\n", file_get_contents($path));
        self::assertTrue($file->model()->journal()->isEmpty());
    }

    public function testCompareBeforeWriteUsesBytesRatherThanFileMetadata(): void
    {
        $source = "```php\nold();\n```\n";
        $path = $this->writeTempFile($source);
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("local();\n");
        file_put_contents($path, $source);

        $file->save(new SaveOptions(compareBeforeWrite: true));

        self::assertSame("```php\nlocal();\n```\n", file_get_contents($path));
    }

    public function testProtectedSaveRefreshesItsFingerprintAfterEveryWrite(): void
    {
        $path = $this->writeTempFile("```php\r\nold();\r\n```\r\n");
        chmod($path, 0o640);
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("first();\n");

        $file->save(new SaveOptions(compareBeforeWrite: true));

        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("second();\n");
        $file->save(new SaveOptions(compareBeforeWrite: true));

        clearstatcache(true, $path);
        self::assertSame("```php\r\nsecond();\r\n```\r\n", file_get_contents($path));
        self::assertSame(0o640, fileperms($path) & 0o777);
        self::assertTrue($file->model()->journal()->isEmpty());
    }

    public function testProtectedNonAtomicSaveAlsoChecksTheOpenedBytes(): void
    {
        $path = $this->writeTempFile("```php\nold();\n```\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("local();\n");
        file_put_contents($path, "```php\nexternal();\n```\n");

        $this->expectException(FileConflictException::class);

        try {
            $file->save(new SaveOptions(atomic: false, compareBeforeWrite: true));
        } finally {
            self::assertSame("```php\nexternal();\n```\n", file_get_contents($path));
        }
    }

    public function testProtectedSaveRejectsADeletedOpenedFile(): void
    {
        $path = $this->writeTempFile("```php\nold();\n```\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("local();\n");
        unlink($path);

        $this->expectException(FileConflictException::class);

        try {
            $file->save(new SaveOptions(compareBeforeWrite: true));
        } finally {
            self::assertFileDoesNotExist($path);
            self::assertFalse($file->model()->journal()->isEmpty());
        }
    }

    public function testProtectedSaveAsCreatesANewTargetAndTracksIt(): void
    {
        $source = $this->writeTempFile("```php\nold();\n```\n");
        $target = $this->tempPath();
        $file = Markdown::github()->open($source);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("copied();\n");

        $file->saveAs($target, new SaveOptions(compareBeforeWrite: true));
        self::assertSame("```php\ncopied();\n```\n", file_get_contents($target));

        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("local();\n");
        file_put_contents($target, "```php\nexternal();\n```\n");

        try {
            $file->save(new SaveOptions(compareBeforeWrite: true));
            self::fail('Expected the copied target change to prevent saving.');
        } catch (FileConflictException) {
            self::assertSame($target, $file->path());
            self::assertSame("```php\nexternal();\n```\n", file_get_contents($target));
        }
    }

    public function testProtectedSaveAsRefusesAnExistingUnopenedTarget(): void
    {
        $source = $this->writeTempFile("```php\nold();\n```\n");
        $target = $this->writeTempFile("# Existing target\n");
        $file = Markdown::github()->open($source);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);
        $block->replaceCode("copied();\n");

        try {
            $file->saveAs($target, new SaveOptions(compareBeforeWrite: true));
            self::fail('Expected the existing target to prevent saveAs.');
        } catch (FileConflictException $error) {
            self::assertSame(
                \sprintf('Markdown file "%s" already exists and was not opened by this document.', $target),
                $error->getMessage(),
            );
            self::assertSame($source, $file->path());
            self::assertSame("# Existing target\n", file_get_contents($target));
            self::assertFalse($file->model()->journal()->isEmpty());
        }
    }

    public function testSaveValidatesEditedBytesBeforeReplacingTheFile(): void
    {
        $path = $this->writeTempFile("# A\n");
        $options = (new ParseOptions())->withMaxSourceBytes(4);
        $file = Markdown::github()->open($path, $options);
        $file->section('A')->append("B\n");

        $this->expectException(SourceSizeLimitException::class);

        try {
            $file->save();
        } finally {
            self::assertSame("# A\n", file_get_contents($path));
            self::assertFalse($file->model()->journal()->isEmpty());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (false !== @lstat($path)) {
                @unlink($path);
            }
        }

        foreach ($this->directories as $directory) {
            @rmdir($directory);
        }
    }

    private function writeTempFile(string $bytes): string
    {
        $path = $this->tempPath();
        file_put_contents($path, $bytes);

        return $path;
    }

    private function tempPath(): string
    {
        $path = \sys_get_temp_dir() . '/alto-markdown-save-' . \bin2hex(\random_bytes(8)) . '.md';
        $this->paths[] = $path;

        return $path;
    }

    private function tempDirectory(): string
    {
        $directory = \sys_get_temp_dir() . '/alto-markdown-save-' . \bin2hex(\random_bytes(8));
        self::assertTrue(mkdir($directory));
        $this->directories[] = $directory;

        return $directory;
    }

    private function createSymlink(string $target, string $link): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('Symbolic-link behavior is POSIX-specific.');
        }

        if (!symlink($target, $link)) {
            self::markTestSkipped('The current filesystem does not allow symbolic links.');
        }
    }
}
