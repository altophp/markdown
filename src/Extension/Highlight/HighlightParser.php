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

namespace Alto\Markdown\Extension\Highlight;

use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HighlightParser implements InlineParser
{
    public function triggerByte(): string
    {
        return '=';
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        $offset = $context->offset();
        $remaining = $context->remaining();

        if (!str_starts_with($remaining, '==')
            || str_starts_with($remaining, '===')
            || ($offset > 0 && '=' === $context->slice($offset - 1, $offset))
        ) {
            return null;
        }

        $first = $remaining[2] ?? '';
        if ('' === $first || self::isWhitespace($first)) {
            return null;
        }

        $searchOffset = 2;
        while (false !== $close = strpos($remaining, '==', $searchOffset)) {
            $previous = $remaining[$close - 1];
            $next = $remaining[$close + 2] ?? '';

            if ("\n" === $previous || "\r" === $previous) {
                return null;
            }

            if (!self::isWhitespace($previous) && '=' !== $next) {
                $text = substr($remaining, 2, $close - 2);
                if (!str_contains($text, "\n") && !str_contains($text, "\r")) {
                    return new InlineParseResult(
                        $offset + $close + 2,
                        new InlineNode($text),
                    );
                }

                return null;
            }

            $searchOffset = $close + 2;
        }

        return null;
    }

    private static function isWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\n" === $byte || "\r" === $byte;
    }
}
