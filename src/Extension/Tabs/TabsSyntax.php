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

namespace Alto\Markdown\Extension\Tabs;

use Alto\Markdown\Parser\ParserState;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class TabsSyntax
{
    public const int MAX_DEPTH = 32;

    public const int MAX_TITLE_BYTES = 256;

    private function __construct()
    {
    }

    public static function groupMarker(ParserState $state): ?string
    {
        $line = self::line($state);

        if (null === $line || 1 !== preg_match('/^(@+)tabs[ \t]*$/D', $line, $matches)) {
            return null;
        }

        if (\strlen($matches[1]) > self::MAX_DEPTH) {
            return null;
        }

        return $matches[1];
    }

    public static function closesGroup(ParserState $state, string $marker): bool
    {
        return $marker.'endtabs' === rtrim(self::line($state) ?? '', " \t");
    }

    public static function tabTitle(ParserState $state, string $marker): ?string
    {
        $line = self::line($state);
        $prefix = $marker.'tab';

        if (null === $line
            || !str_starts_with($line, $prefix)
            || !isset($line[\strlen($prefix)])
            || (' ' !== $line[\strlen($prefix)] && "\t" !== $line[\strlen($prefix)])
        ) {
            return null;
        }

        $title = trim(substr($line, \strlen($prefix)), " \t");
        $length = \strlen($title);

        if (0 === $length || $length > self::MAX_TITLE_BYTES) {
            return null;
        }

        $quote = $title[0];
        if ('"' === $quote || "'" === $quote) {
            if ($length < 3 || $title[$length - 1] !== $quote) {
                return null;
            }

            $title = substr($title, 1, -1);
        }

        if ('' === $title || 1 === preg_match('/[\x00-\x1F\x7F]/', $title)) {
            return null;
        }

        return $title;
    }

    private static function line(ParserState $state): ?string
    {
        $first = $state->firstNonSpaceFrom();

        if ($first >= $state->lineContentEnd || $state->cursorIndentFrom($first) > 3) {
            return null;
        }

        return $state->buffer->substring($first, $state->lineContentEnd);
    }
}
