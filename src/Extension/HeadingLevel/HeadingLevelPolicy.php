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

namespace Alto\Markdown\Extension\HeadingLevel;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HeadingLevelPolicy
{
    /**
     * @param \Closure(int): mixed $resolver
     */
    private function __construct(private \Closure $resolver) {}

    /**
     * @param array<int, int> $levels
     */
    public static function map(array $levels): self
    {
        foreach ($levels as $from => $to) {
            if (!\is_int($from) || $from < 1 || $from > 6) {
                throw new InvalidExtensionException('Heading level map keys must be integers between 1 and 6.');
            }
            if (!\is_int($to) || $to < 1 || $to > 6) {
                throw new InvalidExtensionException('Heading level map values must be integers between 1 and 6.');
            }
        }

        return new self(static fn(int $level): ?int => $levels[$level] ?? null);
    }

    public static function shift(int $offset): self
    {
        if ($offset < -5 || $offset > 5) {
            throw new InvalidExtensionException('Heading level shift must be between -5 and 5.');
        }

        return new self(static fn(int $level): int => $level + $offset);
    }

    /**
     * @param callable(int): mixed $resolver
     */
    public static function using(callable $resolver): self
    {
        return new self(\Closure::fromCallable($resolver));
    }

    /**
     * @internal
     */
    public function resolve(int $level): ?int
    {
        $resolved = ($this->resolver)($level);

        if (null === $resolved) {
            return null;
        }

        if (!\is_int($resolved) || $resolved < 1 || $resolved > 6) {
            throw new InvalidExtensionException(\sprintf('Rendered heading level must be an integer between 1 and 6, got %s for level %d.', self::describe($resolved), $level));
        }

        return $resolved;
    }

    private static function describe(mixed $value): string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_string($value)) {
            $display = \strlen($value) > 40 ? substr($value, 0, 37) . '...' : $value;

            return \sprintf('%s (string)', var_export($display, true));
        }

        if (\is_bool($value)) {
            return $value ? 'true (bool)' : 'false (bool)';
        }

        return get_debug_type($value);
    }
}
