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

namespace Alto\Markdown\Extension\HeadingPermalink;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HeadingPermalinkPolicy
{
    public function __construct(
        public int $minLevel = 1,
        public int $maxLevel = 6,
        public HeadingPermalinkPosition $position = HeadingPermalinkPosition::Before,
        public string $idPrefix = 'content',
        public bool $applyIdToHeading = false,
        public string $headingClass = '',
        public string $fragmentPrefix = 'content',
        public string $htmlClass = 'heading-permalink',
        public string $title = 'Permalink',
        public string $symbol = '¶',
        public bool $ariaHidden = true,
    ) {
        if ($minLevel < 1 || $minLevel > 6) {
            throw new InvalidExtensionException('Heading permalink minimum level must be between 1 and 6.');
        }
        if ($maxLevel < 1 || $maxLevel > 6) {
            throw new InvalidExtensionException('Heading permalink maximum level must be between 1 and 6.');
        }
        if ($minLevel > $maxLevel) {
            throw new InvalidExtensionException('Heading permalink minimum level cannot exceed its maximum level.');
        }
    }

    public function appliesTo(int $level): bool
    {
        return $level >= $this->minLevel && $level <= $this->maxLevel;
    }

    /**
     * Join a configured prefix and slug without forcing a leading hyphen.
     *
     * @internal
     */
    public static function prefixed(string $prefix, string $slug): string
    {
        return '' === $prefix ? $slug : $prefix.'-'.$slug;
    }
}
