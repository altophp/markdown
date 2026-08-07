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
final class ReferenceCountLimitException extends ParseLimitException
{
    public function __construct(
        public readonly int $maxReferenceCount,
        public readonly int $attemptedReferenceCount,
        public readonly int $byteOffset,
    ) {
        parent::__construct(\sprintf(
            'Input would define %d unique references, exceeding the configured limit of %d at byte offset %d.',
            $attemptedReferenceCount,
            $maxReferenceCount,
            $byteOffset,
        ));
    }
}
