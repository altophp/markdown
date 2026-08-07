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

namespace Alto\Markdown\Extension\TableOfContents;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TableOfContentsPolicy
{
    private const int MAX_CLASS_BYTES = 256;

    private const int MAX_ID_BYTES = 128;

    private const int MAX_TITLE_BYTES = 512;

    private const int MAX_MARKER_BYTES = 64;

    public function __construct(
        public int $minLevel = 1,
        public int $maxLevel = 6,
        public TableOfContentsStyle $style = TableOfContentsStyle::Bullet,
        public string $htmlClass = 'table-of-contents',
        public string $id = 'toc',
        public ?string $title = null,
        public string $marker = '@toc',
    ) {
        if ($minLevel < 1 || $minLevel > 6) {
            throw new InvalidExtensionException('Table of contents minimum level must be between 1 and 6.');
        }
        if ($maxLevel < 1 || $maxLevel > 6) {
            throw new InvalidExtensionException('Table of contents maximum level must be between 1 and 6.');
        }
        if ($minLevel > $maxLevel) {
            throw new InvalidExtensionException('Table of contents minimum level cannot exceed its maximum level.');
        }
        if (\strlen($htmlClass) > self::MAX_CLASS_BYTES || 1 === preg_match('/[\x00-\x1f\x7f]/D', $htmlClass)) {
            throw new InvalidExtensionException('Table of contents HTML class must be at most 256 bytes and contain no control characters.');
        }
        if (
            \strlen($id) > self::MAX_ID_BYTES
            || 1 === preg_match('/[\x00-\x20\x7f]/D', $id)
        ) {
            throw new InvalidExtensionException('Table of contents ID must be at most 128 bytes and contain no whitespace or control characters.');
        }
        if (
            null !== $title
            && (
                \strlen($title) > self::MAX_TITLE_BYTES
                || 1 === preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/D', $title)
            )
        ) {
            throw new InvalidExtensionException('Table of contents title must be at most 512 bytes and contain no unsupported control characters.');
        }
        if (
            '' === $marker
            || \strlen($marker) > self::MAX_MARKER_BYTES
            || 1 !== preg_match('/^[\x21-\x7e]+$/D', $marker)
            || str_contains($marker, '{')
            || str_contains($marker, '}')
        ) {
            throw new InvalidExtensionException('Table of contents marker must contain 1 to 64 visible ASCII bytes without braces.');
        }
    }
}
