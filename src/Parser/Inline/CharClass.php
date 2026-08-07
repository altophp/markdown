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

namespace Alto\Markdown\Parser\Inline;

/**
 * Character classification for the flanking rules (SPEC section 7 slow
 * path): bytewise for ASCII, single-codepoint PCRE \p checks past 0x7F.
 * Content boundaries count as whitespace.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class CharClass
{
    public const int OTHER = 0;
    public const int WHITESPACE = 1;
    public const int PUNCTUATION = 2;

    private function __construct()
    {
    }

    /**
     * Classifies the codepoint whose last byte ends at $offset (exclusive),
     * i.e. the character before $offset; boundary is whitespace.
     */
    public static function before(string $text, int $offset): int
    {
        if ($offset <= 0) {
            return self::WHITESPACE;
        }

        $byte = \ord($text[$offset - 1]);

        if ($byte < 0x80) {
            return self::ofAscii($byte);
        }

        // Walk back to the UTF-8 lead byte.
        $start = $offset - 1;

        while ($start > 0 && 0x80 === (\ord($text[$start]) & 0xC0)) {
            --$start;
        }

        return self::ofMultibyte(substr($text, $start, $offset - $start));
    }

    /**
     * Classifies the codepoint starting at $offset; boundary is whitespace.
     */
    public static function after(string $text, int $offset): int
    {
        if ($offset >= \strlen($text)) {
            return self::WHITESPACE;
        }

        $byte = \ord($text[$offset]);

        if ($byte < 0x80) {
            return self::ofAscii($byte);
        }

        $length = match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            default => 2,
        };

        return self::ofMultibyte(substr($text, $offset, $length));
    }

    private static function ofAscii(int $byte): int
    {
        if (0x20 === $byte || 0x09 === $byte || 0x0A === $byte || 0x0C === $byte || 0x0D === $byte) {
            return self::WHITESPACE;
        }

        if (($byte >= 0x21 && $byte <= 0x2F) || ($byte >= 0x3A && $byte <= 0x40) || ($byte >= 0x5B && $byte <= 0x60) || ($byte >= 0x7B && $byte <= 0x7E)) {
            return self::PUNCTUATION;
        }

        return self::OTHER;
    }

    private static function ofMultibyte(string $char): int
    {
        if (1 === preg_match('/^\p{Zs}$/u', $char)) {
            return self::WHITESPACE;
        }

        if (1 === preg_match('/^[\p{P}\p{S}]$/u', $char)) {
            return self::PUNCTUATION;
        }

        return self::OTHER;
    }
}
