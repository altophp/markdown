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

namespace Alto\Markdown\Tests\Resource;

require_once __DIR__ . '/FilesystemFunctionMocks.php';

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\ResourceDeniedException;
use Alto\Markdown\Exception\ResourceNotFoundException;
use Alto\Markdown\Exception\ResourceTooLargeException;
use Alto\Markdown\Exception\UnsupportedResourceException;
use Alto\Markdown\Resource\FilesystemResourceResolver;
use Alto\Markdown\Resource\ResourceRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilesystemFunctionState
{
    public static string|false|null $nextRealpath = null;

    public static bool $failNextOpen = false;

    /**
     * @var list<'pass'|'fail'|'directory'|'different-identity'|'missing-mode'|'missing-size'>
     */
    public static array $fstatActions = [];

    public static string|false|null $nextRead = null;

    public static function reset(): void
    {
        self::$nextRealpath = null;
        self::$failNextOpen = false;
        self::$fstatActions = [];
        self::$nextRead = null;
    }
}

final class FilesystemResourceResolverTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        FilesystemFunctionState::reset();
        $this->root = \sys_get_temp_dir() . '/alto-markdown-resource-' . \bin2hex(\random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o755, true));
    }

    protected function tearDown(): void
    {
        FilesystemFunctionState::reset();
        $this->remove($this->root);
    }

    public function testResolvesEmptyAndExactLimitFilesWithStableOpaqueIds(): void
    {
        $this->write('Exact.MD', '12345');
        $this->write('empty.md', '');
        $resolver = new FilesystemResourceResolver($this->root, ['.MD'], 5);
        $request = new ResourceRequest('Exact.MD', 'source');

        $resource = $resolver->resolve($request);
        $empty = $resolver->resolve(new ResourceRequest('empty.md', 'source'));

        self::assertSame('12345', $resource->bytes);
        self::assertSame('', $empty->bytes);
        self::assertSame($resource->id, $resolver->resolve($request)->id);
        self::assertMatchesRegularExpression('/^filesystem:[a-f0-9]{64}:/D', $resource->id);
        self::assertStringNotContainsString($this->root, $resource->id);
    }

    public function testFilesystemRootKeepsItsPathSeparatorInvariant(): void
    {
        if ('/' !== \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('Unix filesystem root form is unavailable.');
        }

        $this->write('root.md', "root\n");
        $path = realpath($this->root . '/root.md');
        self::assertIsString($path);

        $resource = new FilesystemResourceResolver('/', ['md'])
            ->resolve(new ResourceRequest(ltrim($path, '/'), 'source'));

        self::assertSame("root\n", $resource->bytes);
    }

    public function testOriginIdResolvesRelativeToTheOriginResource(): void
    {
        $this->write('docs/guide.md', "# Guide\n");
        $this->write('docs/parts/intro.md', "Introduction.\n");
        $resolver = new FilesystemResourceResolver($this->root);
        $guide = $resolver->resolve(new ResourceRequest('docs/guide.md', 'include'));

        $intro = $resolver->resolve(new ResourceRequest(
            'parts/intro.md',
            'include',
            $guide->id,
        ));

        self::assertSame("Introduction.\n", $intro->bytes);
        self::assertNotSame($guide->id, $intro->id);
    }

    public function testNormalizesCurrentAndRepeatedPathSegments(): void
    {
        $this->write('docs/target.md', "target\n");
        $resolver = new FilesystemResourceResolver($this->root);

        $resource = $resolver->resolve(new ResourceRequest('./docs//target.md', 'include'));

        self::assertSame("target\n", $resource->bytes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deniedReferences(): iterable
    {
        yield 'null byte' => ["bad\x00.md"];
        yield 'Unix absolute' => ['/etc/passwd'];
        yield 'backslash rooted' => ['\\server\share\file.md'];
        yield 'UNC slash' => ['//server/share/file.md'];
        yield 'Windows drive backslash' => ['C:\\secret.md'];
        yield 'Windows drive slash' => ['C:/secret.md'];
        yield 'Windows drive relative' => ['C:secret.md'];
        yield 'parent traversal' => ['docs/../secret.md'];
    }

    #[DataProvider('deniedReferences')]
    public function testDeniesUnsafePathFormsPortably(string $reference): void
    {
        $resolver = new FilesystemResourceResolver($this->root);
        $request = new ResourceRequest($reference, 'include');

        try {
            $resolver->resolve($request);
            self::fail('Unsafe resource reference must be denied.');
        } catch (ResourceDeniedException $exception) {
            self::assertSame($request, $exception->request);
        }
    }

    public function testDistinguishesMissingUnsupportedAndOversizedResources(): void
    {
        $this->write('source.php', '123456');
        $this->write('secret.env', 'hidden');
        $resolver = new FilesystemResourceResolver($this->root, ['php'], 5);

        try {
            $resolver->resolve(new ResourceRequest('missing.php', 'source'));
            self::fail('Missing file must fail.');
        } catch (ResourceNotFoundException $exception) {
            self::assertSame('missing.php', $exception->request->reference);
        }

        try {
            $resolver->resolve(new ResourceRequest('secret.env', 'source'));
            self::fail('Unsupported extension must fail.');
        } catch (UnsupportedResourceException $exception) {
            self::assertSame('secret.env', $exception->request->reference);
        }

        try {
            $resolver->resolve(new ResourceRequest('source.php', 'source'));
            self::fail('Oversized file must fail.');
        } catch (ResourceTooLargeException $exception) {
            self::assertSame(5, $exception->maxBytes);
            self::assertSame(6, $exception->actualBytes);
        }
    }

    public function testRejectsDirectoriesAndNonDirectoryParents(): void
    {
        self::assertTrue(mkdir($this->root . '/directory'));
        $this->write('parent.md', 'not a directory');
        $resolver = new FilesystemResourceResolver($this->root);

        foreach (['directory', 'parent.md/child.md'] as $reference) {
            try {
                $resolver->resolve(new ResourceRequest($reference, 'include'));
                self::fail('Non-regular resource must be denied.');
            } catch (ResourceDeniedException $exception) {
                self::assertSame($reference, $exception->request->reference);
            }
        }
    }

    public function testRejectsFinalAndIntermediateSymlinks(): void
    {
        $this->write('real/file.md', "content\n");

        if (!@symlink($this->root . '/real/file.md', $this->root . '/file-link.md')) {
            self::markTestSkipped('Symbolic links are unavailable.');
        }
        self::assertTrue(@symlink($this->root . '/real', $this->root . '/directory-link'));

        $resolver = new FilesystemResourceResolver($this->root);

        foreach (['file-link.md', 'directory-link/file.md'] as $reference) {
            try {
                $resolver->resolve(new ResourceRequest($reference, 'include'));
                self::fail('Every symbolic-link component must be denied.');
            } catch (ResourceDeniedException $exception) {
                self::assertStringContainsString('symbolic links', $exception->getMessage());
            }
        }
    }

    public function testRejectsASymlinkConfiguredAsTheRoot(): void
    {
        self::assertTrue(mkdir($this->root . '/real-root'));

        if (!@symlink($this->root . '/real-root', $this->root . '/root-link')) {
            self::markTestSkipped('Symbolic links are unavailable.');
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('non-symlink directory');

        new FilesystemResourceResolver($this->root . '/root-link');
    }

    public function testRejectsForeignAndMalformedOriginIds(): void
    {
        $this->write('target.md', "target\n");
        $resolver = new FilesystemResourceResolver($this->root);
        $resource = $resolver->resolve(new ResourceRequest('target.md', 'include'));
        $separator = strrpos($resource->id, ':');
        self::assertIsInt($separator);
        $idPrefix = substr($resource->id, 0, $separator + 1);

        foreach ([
            'other:origin',
            $idPrefix . '***',
            $idPrefix . $this->base64UrlEncode('../target.md'),
            $idPrefix . $this->base64UrlEncode('docs\\target.md'),
        ] as $originId) {
            try {
                $resolver->resolve(new ResourceRequest('target.md', 'include', $originId));
                self::fail('Foreign origin ID must be unsupported.');
            } catch (UnsupportedResourceException $exception) {
                self::assertSame($originId, $exception->request->originId);
            }
        }
    }

    public function testRejectsInvalidConfigurationAndRequestMetadata(): void
    {
        $reflection = new \ReflectionClass(FilesystemResourceResolver::class);

        foreach ([
            fn(): FilesystemResourceResolver => new FilesystemResourceResolver($this->root . "\x00"),
            static fn(): FilesystemResourceResolver => new FilesystemResourceResolver('/path/that/does/not/exist'),
            fn(): FilesystemResourceResolver => new FilesystemResourceResolver($this->root, []),
            fn(): FilesystemResourceResolver => new FilesystemResourceResolver($this->root, ['bad.ext']),
            fn(): FilesystemResourceResolver => new FilesystemResourceResolver($this->root, ['md'], -1),
            fn(): FilesystemResourceResolver => new FilesystemResourceResolver($this->root, ['md'], \PHP_INT_MAX),
            fn(): object => $reflection->newInstanceArgs([$this->root, [42]]),
            static fn(): ResourceRequest => new ResourceRequest('', 'include'),
            static fn(): ResourceRequest => new ResourceRequest('file.md', 'Invalid Purpose'),
            static fn(): ResourceRequest => new ResourceRequest('file.md', 'include', ''),
        ] as $factory) {
            try {
                $factory();
                self::fail('Invalid resource configuration must fail.');
            } catch (InvalidMarkdownArgumentException) {
            }
        }
    }

    public function testRejectsAPathWithoutAFileSegment(): void
    {
        $resolver = new FilesystemResourceResolver($this->root);

        $this->expectException(ResourceDeniedException::class);
        $this->expectExceptionMessage('does not identify a file');

        $resolver->resolve(new ResourceRequest('.', 'include'));
    }

    public function testHandlesDefensiveFilesystemFailures(): void
    {
        $this->write('target.md', "target\n");
        FilesystemFunctionState::$nextRealpath = false;

        try {
            new FilesystemResourceResolver($this->root);
            self::fail('An unresolvable root must fail.');
        } catch (InvalidMarkdownArgumentException $exception) {
            self::assertStringContainsString('resolvable', $exception->getMessage());
        }

        $resolver = new FilesystemResourceResolver($this->root, ['md'], 10);

        foreach ([
            'resolved outside root' => static function (): void {
                FilesystemFunctionState::$nextRealpath = '/outside/root.md';
            },
            'open failure' => static function (): void {
                FilesystemFunctionState::$failNextOpen = true;
            },
            'stat failure' => static function (): void {
                FilesystemFunctionState::$fstatActions = ['fail'];
            },
            'non-regular opened file' => static function (): void {
                FilesystemFunctionState::$fstatActions = ['directory'];
            },
            'missing opened mode' => static function (): void {
                FilesystemFunctionState::$fstatActions = ['missing-mode'];
            },
            'missing opened size' => static function (): void {
                FilesystemFunctionState::$fstatActions = ['missing-size'];
            },
            'changed opened file' => static function (): void {
                FilesystemFunctionState::$fstatActions = ['different-identity'];
            },
            'read failure' => static function (): void {
                FilesystemFunctionState::$nextRead = false;
            },
            'growth during read' => static function (): void {
                FilesystemFunctionState::$nextRead = 'content beyond the configured maximum';
            },
            'stat failure after read' => static function (): void {
                FilesystemFunctionState::$fstatActions = ['pass', 'fail'];
            },
            'changed file after read' => static function (): void {
                FilesystemFunctionState::$fstatActions = ['pass', 'different-identity'];
            },
            'missing mode after read' => static function (): void {
                FilesystemFunctionState::$fstatActions = ['pass', 'missing-mode'];
            },
        ] as $failure => $configureFailure) {
            FilesystemFunctionState::reset();
            $configureFailure();

            try {
                $resolver->resolve(new ResourceRequest('target.md', 'include'));
                self::fail(\sprintf('Defensive filesystem failure "%s" must be exposed.', $failure));
            } catch (ResourceDeniedException|ResourceTooLargeException $exception) {
                self::assertSame('target.md', $exception->request->reference);
            }
        }
    }

    private function write(string $relative, string $bytes): void
    {
        $path = $this->root . '/' . $relative;
        $directory = \dirname($path);

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0o755, true));
        }

        self::assertSame(\strlen($bytes), file_put_contents($path, $bytes));
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if (false !== $entries) {
            foreach ($entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    $this->remove($path . '/' . $entry);
                }
            }
        }

        @rmdir($path);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
