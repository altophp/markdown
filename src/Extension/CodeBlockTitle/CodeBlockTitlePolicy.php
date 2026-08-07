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

namespace Alto\Markdown\Extension\CodeBlockTitle;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CodeBlockTitlePolicy
{
    public function __construct(
        public string $figureClass = 'code-block has-title',
        public string $captionClass = 'code-title',
        public bool $includeDataTitle = true,
        public int $maxInfoBytes = 4096,
        public int $maxTitleBytes = 512,
    ) {
        if ($maxInfoBytes < 1) {
            throw new InvalidExtensionException('Code-block title info limit must be positive.');
        }
        if ($maxTitleBytes < 1 || $maxTitleBytes > $maxInfoBytes) {
            throw new InvalidExtensionException('Code-block title limit must be positive and cannot exceed the info limit.');
        }
    }
}
