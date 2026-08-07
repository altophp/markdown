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

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class SourceSizeLimitException extends ParseLimitException
{
    public function __construct(
        public readonly int $maxSourceBytes,
        public readonly int $sourceBytes,
    ) {
        parent::__construct(\sprintf(
            'Input contains %d bytes, exceeding the configured source limit of %d bytes.',
            $sourceBytes,
            $maxSourceBytes,
        ));
    }
}
