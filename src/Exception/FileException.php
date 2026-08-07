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

namespace Alto\Markdown\Exception;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
abstract class FileException extends \RuntimeException implements MarkdownExceptionInterface
{
    protected function __construct(
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }
}
