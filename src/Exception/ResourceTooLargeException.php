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
final class ResourceTooLargeException extends ResourceResolutionException
{
    public function __construct(
        ResourceRequest $request,
        public readonly int $maxBytes,
        public readonly int $actualBytes,
    ) {
        parent::__construct(
            $request,
            \sprintf(
                'Resource %s contains at least %d bytes, exceeding the configured limit of %d bytes.',
                self::reference($request),
                $actualBytes,
                $maxBytes,
            ),
        );
    }
}
