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
final class InlineCountLimitException extends ParseLimitException
{
    public function __construct(
        public readonly int $maxInlineCount,
        public readonly int $attemptedInlineCount,
        public readonly ?int $byteOffset,
    ) {
        $message = \sprintf(
            'Input would allocate %d inline nodes, exceeding the configured limit of %d.',
            $attemptedInlineCount,
            $maxInlineCount,
        );

        if (null !== $byteOffset) {
            $message = substr($message, 0, -1).\sprintf(' at byte offset %d.', $byteOffset);
        }

        parent::__construct($message);
    }
}
