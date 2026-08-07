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
abstract class ResourceResolutionException extends \RuntimeException implements MarkdownExceptionInterface
{
    public function __construct(
        public readonly ResourceRequest $request,
        string $message,
    ) {
        parent::__construct($message);
    }

    protected static function reference(ResourceRequest $request): string
    {
        $encoded = json_encode(
            $request->reference,
            \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return false === $encoded ? '"<invalid>"' : $encoded;
    }
}
