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

namespace Alto\Markdown\Extension\FrontMatter;

/**
 * @template T
 *
 * @implements FrontMatterDecoder<T>
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CallbackFrontMatterDecoder implements FrontMatterDecoder
{
    /**
     * @var \Closure(string, string): T
     */
    private \Closure $callback;

    /**
     * @param callable(string, string): T $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = \Closure::fromCallable($callback);
    }

    public function decode(string $content, string $fence): mixed
    {
        return ($this->callback)($content, $fence);
    }
}
