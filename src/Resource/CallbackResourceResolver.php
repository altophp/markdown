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

namespace Alto\Markdown\Resource;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CallbackResourceResolver implements ResourceResolver
{
    /**
     * @var \Closure(ResourceRequest): mixed
     */
    private \Closure $callback;

    /**
     * @param callable(ResourceRequest): mixed $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = \Closure::fromCallable($callback);
    }

    public function resolve(ResourceRequest $request): ResolvedResource
    {
        $resource = ($this->callback)($request);

        if (!$resource instanceof ResolvedResource) {
            throw new InvalidMarkdownArgumentException('Resource resolver callbacks must return ResolvedResource.');
        }

        return $resource;
    }
}
