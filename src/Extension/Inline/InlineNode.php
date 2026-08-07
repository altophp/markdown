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

namespace Alto\Markdown\Extension\Inline;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineNode
{
    /**
     * @param array<string, bool|int|string|null> $attributes
     */
    public function __construct(
        public string $text,
        private array $attributes = [],
    ) {
        foreach ($attributes as $name => $value) {
            if (!\is_string($name)) {
                throw new InvalidExtensionException('Inline node attribute names must be strings.');
            }

            if (null !== $value && !\is_bool($value) && !\is_int($value) && !\is_string($value)) {
                throw new InvalidExtensionException(\sprintf('Inline node attribute "%s" must be a boolean, integer, string, or null.', $name));
            }
        }
    }

    public function attribute(string $name): bool|int|string|null
    {
        if (!\array_key_exists($name, $this->attributes)) {
            throw new InvalidExtensionException(\sprintf('Inline node attribute "%s" is not defined.', $name));
        }

        return $this->attributes[$name];
    }

    public function string(string $name): string
    {
        $value = $this->attribute($name);

        if (!\is_string($value)) {
            throw new InvalidExtensionException(\sprintf('Inline node attribute "%s" is not a string.', $name));
        }

        return $value;
    }

    public function int(string $name): int
    {
        $value = $this->attribute($name);

        if (!\is_int($value)) {
            throw new InvalidExtensionException(\sprintf('Inline node attribute "%s" is not an integer.', $name));
        }

        return $value;
    }
}
