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

namespace Alto\Markdown\Extension\SmartPunctuation;

use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SmartEllipsisParser implements InlineParser
{
    public function triggerByte(): string
    {
        return '.';
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        $remaining = $context->remaining();
        $length = str_starts_with($remaining, '...')
            ? 3
            : (str_starts_with($remaining, '. . .') ? 5 : 0);

        if (0 === $length) {
            return null;
        }

        return new InlineParseResult(
            $context->offset() + $length,
            new InlineNode("\u{2026}"),
        );
    }
}
