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

namespace Alto\Markdown\Extension\Attributes;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class AttributesPolicy
{
    /**
     * @var array<string, true>
     */
    private array $allowed;

    /**
     * @param list<string> $allowed
     */
    public function __construct(
        array $allowed = ['id', 'class'],
        public int $maxAttributes = 32,
        public int $maxListBytes = 1024,
        public int $maxValueBytes = 512,
    ) {
        if ($maxAttributes < 1 || $maxAttributes > 256) {
            throw new InvalidExtensionException('Attribute count limit must be between 1 and 256.');
        }
        if ($maxListBytes < 16 || $maxListBytes > 65_536) {
            throw new InvalidExtensionException('Attribute-list byte limit must be between 16 and 65536.');
        }
        if ($maxValueBytes < 1 || $maxValueBytes > $maxListBytes) {
            throw new InvalidExtensionException('Attribute value byte limit must be positive and no greater than the list limit.');
        }

        $names = [];

        foreach ($allowed as $name) {
            if (1 !== preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]{0,63}$/D', $name)) {
                throw new InvalidExtensionException(\sprintf('Allowed HTML attribute name "%s" is invalid.', $name));
            }

            $name = strtolower($name);
            if (str_starts_with($name, 'on')) {
                throw new InvalidExtensionException(\sprintf('Event handler attribute "%s" cannot be enabled for Markdown content.', $name));
            }
            if (isset($names[$name])) {
                throw new InvalidExtensionException(\sprintf('Allowed HTML attribute "%s" is duplicated.', $name));
            }

            $names[$name] = true;
        }

        $this->allowed = $names;
    }

    public function allows(string $name): bool
    {
        return isset($this->allowed[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    public function allowed(): array
    {
        return array_keys($this->allowed);
    }
}
