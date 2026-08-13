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

namespace Alto\Markdown\Resource;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\ResourceDeniedException;
use Alto\Markdown\Exception\ResourceNotFoundException;
use Alto\Markdown\Exception\ResourceTooLargeException;
use Alto\Markdown\Exception\UnsupportedResourceException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FilesystemResourceResolver implements ResourceResolver
{
    private const int FILE_TYPE_MASK = 0o170000;

    private const int DIRECTORY_TYPE = 0o040000;

    private const int REGULAR_FILE_TYPE = 0o100000;

    private const int SYMLINK_TYPE = 0o120000;

    private string $root;

    private string $idPrefix;

    /**
     * @var array<string, true>
     */
    private array $allowedExtensions;

    /**
     * @param list<string> $allowedExtensions
     */
    public function __construct(
        string $root,
        array $allowedExtensions = ['md', 'markdown'],
        private int $maxBytes = 1_048_576,
    ) {
        if (str_contains($root, "\x00")) {
            throw new InvalidMarkdownArgumentException('Resource root must not contain null bytes.');
        }

        $rootMetadata = @lstat($root);
        if (false === $rootMetadata || self::DIRECTORY_TYPE !== ($rootMetadata['mode'] & self::FILE_TYPE_MASK)) {
            throw new InvalidMarkdownArgumentException('Resource root must be an existing non-symlink directory.');
        }

        $resolvedRoot = realpath($root);
        if (false === $resolvedRoot) {
            throw new InvalidMarkdownArgumentException('Resource root must be resolvable.');
        }

        if ($maxBytes < 0 || \PHP_INT_MAX === $maxBytes) {
            throw new InvalidMarkdownArgumentException('Resource maximum bytes must be between zero and PHP_INT_MAX - 1.');
        }

        if ([] === $allowedExtensions) {
            throw new InvalidMarkdownArgumentException('At least one resource file extension must be allowed.');
        }

        $extensions = [];
        foreach ($allowedExtensions as $extension) {
            if (!\is_string($extension)) {
                throw new InvalidMarkdownArgumentException('Resource file extensions must be strings.');
            }

            $extension = strtolower(ltrim($extension, '.'));
            if (1 !== preg_match('/^[a-z0-9][a-z0-9+_-]*$/D', $extension)) {
                throw new InvalidMarkdownArgumentException(\sprintf('Invalid resource file extension "%s".', $extension));
            }

            $extensions[$extension] = true;
        }

        $this->root = $resolvedRoot;
        $this->idPrefix = 'filesystem:' . hash('sha256', $this->root) . ':';
        $this->allowedExtensions = $extensions;
    }

    public function resolve(ResourceRequest $request): ResolvedResource
    {
        $segments = $this->referenceSegments($request);

        if (null !== $request->originId) {
            $originSegments = $this->originSegments($request);
            array_pop($originSegments);
            $segments = [...$originSegments, ...$segments];
        }

        [$path, $metadata] = $this->checkedPath($request, $segments);
        $resolved = realpath($path);

        if (false === $resolved || !$this->insideRoot($resolved)) {
            throw new ResourceDeniedException($request, 'the resolved path is outside the configured root');
        }

        $relative = $this->relativePath($resolved);
        $extension = strtolower(pathinfo($relative, \PATHINFO_EXTENSION));

        if (!isset($this->allowedExtensions[$extension])) {
            throw new UnsupportedResourceException($request, \sprintf('file extension ".%s" is not allowed', $extension));
        }

        $handle = @fopen($resolved, 'rb');
        if (false === $handle) {
            throw new ResourceDeniedException($request, 'the file could not be opened for reading');
        }

        try {
            $opened = @fstat($handle);
            $openedMode = false === $opened ? null : ($opened['mode'] ?? null);
            $openedSize = false === $opened ? null : ($opened['size'] ?? null);
            if (
                !\is_int($openedMode)
                || !\is_int($openedSize)
                || self::REGULAR_FILE_TYPE !== ($openedMode & self::FILE_TYPE_MASK)
            ) {
                throw new ResourceDeniedException($request, 'the opened resource is not a regular file');
            }

            if (!$this->sameFile($metadata, $opened)) {
                throw new ResourceDeniedException($request, 'the file changed while it was being opened');
            }

            if ($openedSize > $this->maxBytes) {
                throw new ResourceTooLargeException($request, $this->maxBytes, $openedSize);
            }

            $bytes = @stream_get_contents($handle, $this->maxBytes + 1);
            if (false === $bytes) {
                throw new ResourceDeniedException($request, 'the file could not be read');
            }

            if (\strlen($bytes) > $this->maxBytes) {
                throw new ResourceTooLargeException($request, $this->maxBytes, \strlen($bytes));
            }

            $afterRead = @fstat($handle);
            if (false === $afterRead || !$this->sameFile($opened, $afterRead)) {
                throw new ResourceDeniedException($request, 'the opened file changed while it was being read');
            }
        } finally {
            fclose($handle);
        }

        return new ResolvedResource($this->resourceId($relative), $bytes);
    }

    /**
     * @return list<string>
     */
    private function referenceSegments(ResourceRequest $request): array
    {
        $reference = $request->reference;

        if (
            str_contains($reference, "\x00")
            || str_starts_with($reference, '/')
            || str_starts_with($reference, '\\')
            || 1 === preg_match('/^[A-Za-z]:/D', $reference)
        ) {
            throw new ResourceDeniedException($request, 'only relative paths without null bytes are accepted');
        }

        $segments = explode('/', str_replace('\\', '/', $reference));

        $normalized = [];
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                throw new ResourceDeniedException($request, 'parent traversal is not allowed');
            }

            $normalized[] = $segment;
        }

        if ([] === $normalized) {
            throw new ResourceDeniedException($request, 'the path does not identify a file');
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function originSegments(ResourceRequest $request): array
    {
        $originId = $request->originId ?? '';

        if (!str_starts_with($originId, $this->idPrefix)) {
            throw new UnsupportedResourceException($request, 'the origin ID belongs to another resolver');
        }

        $encoded = substr($originId, \strlen($this->idPrefix));
        $decoded = $this->base64UrlDecode($encoded);

        if (
            null === $decoded
            || '' === $decoded
            || str_contains($decoded, '\\')
            || $encoded !== $this->base64UrlEncode($decoded)
        ) {
            throw new UnsupportedResourceException($request, 'the origin ID is invalid');
        }

        $segments = explode('/', $decoded);
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment || str_contains($segment, "\x00")) {
                throw new UnsupportedResourceException($request, 'the origin ID is invalid');
            }
        }

        return $segments;
    }

    /**
     * @param list<string> $segments
     *
     * @return array{string, array<mixed>}
     */
    private function checkedPath(ResourceRequest $request, array $segments): array
    {
        $path = rtrim($this->root, \DIRECTORY_SEPARATOR);
        $metadata = null;
        $last = \count($segments) - 1;

        foreach ($segments as $index => $segment) {
            $path .= \DIRECTORY_SEPARATOR . $segment;
            $metadata = @lstat($path);

            if (false === $metadata) {
                throw new ResourceNotFoundException($request);
            }

            $type = $metadata['mode'] & self::FILE_TYPE_MASK;
            if (self::SYMLINK_TYPE === $type) {
                throw new ResourceDeniedException($request, 'symbolic links are not allowed');
            }

            if ($index < $last && self::DIRECTORY_TYPE !== $type) {
                throw new ResourceDeniedException($request, 'a parent component is not a directory');
            }
        }

        if (!\is_array($metadata) || self::REGULAR_FILE_TYPE !== ($metadata['mode'] & self::FILE_TYPE_MASK)) {
            throw new ResourceDeniedException($request, 'the resource is not a regular file');
        }

        return [$path, $metadata];
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     */
    private function sameFile(array $expected, array $actual): bool
    {
        $mode = $actual['mode'] ?? null;
        if (!\is_int($mode)) {
            return false;
        }

        return ($expected['dev'] ?? null) === ($actual['dev'] ?? null)
            && ($expected['ino'] ?? null) === ($actual['ino'] ?? null)
            && self::REGULAR_FILE_TYPE === ($mode & self::FILE_TYPE_MASK);
    }

    private function insideRoot(string $path): bool
    {
        return str_starts_with(
            $path,
            rtrim($this->root, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR,
        );
    }

    private function relativePath(string $path): string
    {
        $prefix = rtrim($this->root, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;

        return str_replace(
            \DIRECTORY_SEPARATOR,
            '/',
            substr($path, \strlen($prefix)),
        );
    }

    private function resourceId(string $relative): string
    {
        return $this->idPrefix . $this->base64UrlEncode($relative);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = (4 - \strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);

        return false === $decoded ? null : $decoded;
    }
}
