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

namespace Alto\Markdown\Extension\Block;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlockState
{
    /**
     * Alto stores this extension-owned state without interpreting it.
     *
     * @param array<string, bool|int|string|null> $values
     */
    public function __construct(private array $values = [])
    {
        foreach ($values as $name => $value) {
            if (!\is_string($name)) {
                throw new InvalidExtensionException('Block state names must be strings.');
            }

            if (null !== $value && !\is_bool($value) && !\is_int($value) && !\is_string($value)) {
                throw new InvalidExtensionException(\sprintf('Block state value "%s" must be scalar or null.', $name));
            }
        }
    }

    public function value(string $name): bool|int|string|null
    {
        if (!\array_key_exists($name, $this->values)) {
            throw new InvalidExtensionException(\sprintf('Block state value "%s" is not defined.', $name));
        }

        return $this->values[$name];
    }

    public function int(string $name): int
    {
        $value = $this->value($name);

        if (!\is_int($value)) {
            throw new InvalidExtensionException(\sprintf('Block state value "%s" is not an integer.', $name));
        }

        return $value;
    }

    public function string(string $name): string
    {
        $value = $this->value($name);

        if (!\is_string($value)) {
            throw new InvalidExtensionException(\sprintf('Block state value "%s" is not a string.', $name));
        }

        return $value;
    }

    public function with(string $name, bool|int|string|null $value): self
    {
        $values = $this->values;
        $values[$name] = $value;

        return new self($values);
    }
}
