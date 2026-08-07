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
final readonly class ResourceRequest
{
    public function __construct(
        public string $reference,
        public string $purpose,
        public ?string $originId = null,
    ) {
        if ('' === $reference) {
            throw new InvalidMarkdownArgumentException('Resource reference must not be empty.');
        }

        if (1 !== preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $purpose)) {
            throw new InvalidMarkdownArgumentException('Resource purpose must be a lowercase identifier of at most 64 characters.');
        }

        if (null !== $originId && '' === $originId) {
            throw new InvalidMarkdownArgumentException('Resource origin ID must not be empty.');
        }
    }
}
