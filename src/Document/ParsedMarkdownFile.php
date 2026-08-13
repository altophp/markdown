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

namespace Alto\Markdown\Document;

use Alto\Markdown\Exception\FileConflictException;
use Alto\Markdown\Exception\FileWriteException;
use Alto\Markdown\Exception\MarkdownInternalException;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use Alto\Markdown\Operation\SaveOptions;
use Alto\Markdown\Operation\SymlinkPolicy;
use Alto\Markdown\Profile\Profile;
use Alto\Markdown\Source\LineEnding;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ParsedMarkdownFile extends ParsedMarkdownDocument implements \Alto\Markdown\MarkdownFile
{
    private string $sourceFingerprint;

    public function __construct(
        Profile $profile,
        ParsedDocumentModel $model,
        private string $path,
        private string $anchoredPath,
    ) {
        parent::__construct($profile, $model);
        $this->sourceFingerprint = self::fingerprint($model->source()->bytes);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function save(?SaveOptions $options = null): void
    {
        if ($this->model()->journal()->isEmpty()) {
            return;
        }

        $this->writeAndRebase($this->path, $this->anchoredPath, $options ?? new SaveOptions());
    }

    public function saveAs(string $path, ?SaveOptions $options = null): void
    {
        $anchoredPath = $this->anchorPath($path);
        $this->writeAndRebase($path, $anchoredPath, $options ?? new SaveOptions());
        $this->path = $path;
        $this->anchoredPath = $anchoredPath;
    }

    private function writeAndRebase(string $path, string $anchoredPath, SaveOptions $options): void
    {
        $bytes = $this->bytesToWrite($options);
        $model = $this->parsedModel();
        $syntax = $model->prepareRebase($bytes);
        $this->write($path, $anchoredPath, $bytes, $options);
        $model->adoptRebase($syntax);
        $this->sourceFingerprint = self::fingerprint($bytes);
    }

    private function bytesToWrite(SaveOptions $options): string
    {
        $source = $this->model()->source();
        $bytes = $this->model()->journal()->isEmpty()
            ? $source->bytes
            : new DisjointPatchLowerer()->lower($this->model(), $this->model()->journal())->bytes;

        if (!$options->preserveEol || LineEnding::Lf !== $source->dominantEol) {
            $bytes = $this->normalizeEol($bytes, $source->dominantEol);
        }

        $bytes = \str_starts_with($bytes, "\xEF\xBB\xBF") ? \substr($bytes, 3) : $bytes;

        if ($options->preserveBom && $source->hasBom) {
            return "\xEF\xBB\xBF" . $bytes;
        }

        return $bytes;
    }

    private function write(string $path, string $anchoredPath, string $bytes, SaveOptions $options): void
    {
        $writePath = $this->resolveWritePath($path, $anchoredPath, $options);

        if (!$options->atomic) {
            $this->assertTargetUnchanged($path, $anchoredPath, $writePath, $options);

            if (false === @file_put_contents($writePath, $bytes)) {
                throw new FileWriteException(\sprintf('Unable to write Markdown file "%s".', $path), $path);
            }

            return;
        }

        $directory = \dirname($writePath);
        $metadata = @lstat($writePath);
        $permissions = false === $metadata ? 0o666 & ~umask() : @fileperms($writePath);

        if (false !== $metadata && false === $permissions) {
            throw new FileWriteException(\sprintf('Unable to read permissions for Markdown file "%s".', $path), $path);
        }

        $temporary = @tempnam($directory, '.alto-markdown-');

        if (false === $temporary) {
            throw new FileWriteException(\sprintf('Unable to write Markdown file "%s".', $path), $path);
        }

        try {
            if (false === @file_put_contents($temporary, $bytes)) {
                throw new FileWriteException(\sprintf('Unable to write Markdown file "%s".', $path), $path);
            }

            if (!@chmod($temporary, $permissions & 0o7777)) {
                $action = false === $metadata ? 'set' : 'restore';

                throw new FileWriteException(\sprintf('Unable to %s permissions for Markdown file "%s".', $action, $path), $path);
            }

            $this->assertTargetUnchanged($path, $anchoredPath, $writePath, $options);

            if (!@rename($temporary, $writePath)) {
                throw new FileWriteException(\sprintf('Unable to write Markdown file "%s".', $path), $path);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function assertTargetUnchanged(string $path, string $anchoredPath, string $writePath, SaveOptions $options): void
    {
        if (!$options->compareBeforeWrite) {
            return;
        }

        if ($anchoredPath !== $this->anchoredPath) {
            if (false !== @lstat($writePath) || false !== @lstat($anchoredPath)) {
                throw new FileConflictException($path, \sprintf('Markdown file "%s" already exists and was not opened by this document.', $path));
            }

            return;
        }

        $current = @file_get_contents($writePath);

        if (false === $current || !hash_equals($this->sourceFingerprint, self::fingerprint($current))) {
            throw new FileConflictException($path, \sprintf('Markdown file "%s" changed after it was opened.', $path));
        }
    }

    private function resolveWritePath(string $path, string $anchoredPath, SaveOptions $options): string
    {
        $metadata = @lstat($anchoredPath);

        if (false === $metadata) {
            return $anchoredPath;
        }

        $type = $metadata['mode'] & 0o170000;

        if (0o120000 !== $type) {
            if (0o100000 !== $type) {
                throw new FileWriteException(\sprintf('Refusing to write non-regular Markdown file "%s".', $path), $path);
            }

            return $anchoredPath;
        }

        if (SymlinkPolicy::Reject === $options->symlinks) {
            throw new FileWriteException(\sprintf('Refusing to write Markdown symlink "%s".', $path), $path);
        }

        $resolved = realpath($anchoredPath);

        if (false === $resolved) {
            throw new FileWriteException(\sprintf('Unable to resolve Markdown symlink "%s" to a regular file.', $path), $path);
        }

        $resolvedMetadata = @lstat($resolved);

        if (false === $resolvedMetadata || 0o100000 !== ($resolvedMetadata['mode'] & 0o170000)) {
            throw new FileWriteException(\sprintf('Unable to resolve Markdown symlink "%s" to a regular file.', $path), $path);
        }

        return $resolved;
    }

    private function anchorPath(string $path): string
    {
        $directory = realpath(\dirname($path));

        if (false === $directory) {
            throw new FileWriteException(\sprintf('Unable to resolve parent directory for Markdown file "%s".', $path), $path);
        }

        return \rtrim($directory, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . \basename($path);
    }

    private static function fingerprint(string $bytes): string
    {
        return hash('sha256', $bytes, true);
    }

    private function normalizeEol(string $bytes, LineEnding $eol): string
    {
        $replacement = match ($eol) {
            LineEnding::Lf => "\n",
            LineEnding::CrLf => "\r\n",
            LineEnding::Cr => "\r",
        };

        return (string) \preg_replace("/\r\n|\r|\n/", $replacement, $bytes);
    }

    private function parsedModel(): ParsedDocumentModel
    {
        $model = $this->model();

        if (!$model instanceof ParsedDocumentModel) {
            throw new MarkdownInternalException('ParsedMarkdownFile requires ParsedDocumentModel.');
        }

        return $model;
    }
}
