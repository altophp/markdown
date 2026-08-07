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

namespace Alto\Markdown\Query;

/**
 * @template T
 *
 * @extends \IteratorAggregate<int, T>
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface Collection extends \Countable, \IteratorAggregate
{
    /**
     * @return T|null
     */
    public function first(): mixed;

    /**
     * @return list<T>
     */
    public function all(): array;

    /**
     * @param callable(T): bool $predicate
     *
     * @return self<T>
     */
    public function filter(callable $predicate): self;
}
