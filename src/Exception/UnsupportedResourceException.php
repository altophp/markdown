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

namespace Alto\Markdown\Exception;

use Alto\Markdown\Resource\ResourceRequest;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class UnsupportedResourceException extends ResourceResolutionException
{
    public function __construct(ResourceRequest $request, string $reason)
    {
        parent::__construct(
            $request,
            \sprintf('Resource %s is unsupported: %s.', self::reference($request), $reason),
        );
    }
}
