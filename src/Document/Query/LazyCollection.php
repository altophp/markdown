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

namespace Alto\Markdown\Document\Query;

use Alto\Markdown\Query\Collection;

/**
 * @internal
 *
 * @template T
 *
 * @implements Collection<T>
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LazyCollection implements Collection
{
    /**
     * @param \Closure(): iterable<T> $factory
     */
    public function __construct(private \Closure $factory) {}

    public function first(): mixed
    {
        foreach (($this->factory)() as $item) {
            return $item;
        }

        return null;
    }

    public function all(): array
    {
        return \array_values(\iterator_to_array($this->getIterator(), false));
    }

    public function filter(callable $predicate): Collection
    {
        return new self(function () use ($predicate): \Generator {
            foreach (($this->factory)() as $item) {
                if ($predicate($item)) {
                    yield $item;
                }
            }
        });
    }

    public function count(): int
    {
        $count = 0;

        foreach (($this->factory)() as $_) {
            ++$count;
        }

        return $count;
    }

    public function getIterator(): \Traversable
    {
        foreach (($this->factory)() as $item) {
            yield $item;
        }
    }
}
