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

use Alto\Markdown\Tests\Resource\FilesystemFunctionState;

function realpath(string $filename): string|false
{
    if (null !== FilesystemFunctionState::$nextRealpath) {
        $result = FilesystemFunctionState::$nextRealpath;
        FilesystemFunctionState::$nextRealpath = null;

        return $result;
    }

    return \realpath($filename);
}

/**
 * @return resource|false
 */
function fopen(string $filename, string $mode)
{
    if (FilesystemFunctionState::$failNextOpen) {
        FilesystemFunctionState::$failNextOpen = false;

        return false;
    }

    return \fopen($filename, $mode);
}

/**
 * @param resource $stream
 *
 * @return array<mixed>|false
 */
function fstat($stream): array|false
{
    $metadata = \fstat($stream);
    $action = array_shift(FilesystemFunctionState::$fstatActions);

    if (null === $action || 'pass' === $action) {
        return $metadata;
    }

    if ('fail' === $action || false === $metadata) {
        return false;
    }

    if ('directory' === $action) {
        $metadata['mode'] = 0o040000;
    } elseif ('different-identity' === $action) {
        $metadata['ino'] = ($metadata['ino'] ?? 0) + 1;
    } elseif ('missing-mode' === $action) {
        unset($metadata['mode']);
    } elseif ('missing-size' === $action) {
        unset($metadata['size']);
    }

    return $metadata;
}

/**
 * @param resource $stream
 */
function stream_get_contents($stream, ?int $length = null): string|false
{
    if (null !== FilesystemFunctionState::$nextRead) {
        $result = FilesystemFunctionState::$nextRead;
        FilesystemFunctionState::$nextRead = null;

        return $result;
    }

    return null === $length
        ? \stream_get_contents($stream)
        : \stream_get_contents($stream, $length);
}
