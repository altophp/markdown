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
final class ResourceNotFoundException extends ResourceResolutionException
{
    public function __construct(ResourceRequest $request)
    {
        parent::__construct(
            $request,
            \sprintf('Resource %s was not found.', self::reference($request)),
        );
    }
}
