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
 * Destination handling shared by autolinks and links: percent-encoding
 * for href output (existing percent sequences pass through byte-wise),
 * and backslash-escape plus entity resolution for destinations and
 * titles, which are not inline content and decode here.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class Href
{
    private const string UNSAFE = '"<>[\\]^`{|}';

    private function __construct() {}

    public static function encode(string $url): string
    {
        $out = '';
        $length = \strlen($url);

        for ($i = 0; $i < $length; ++$i) {
            $byte = \ord($url[$i]);

            if ($byte > 0x20 && $byte < 0x7F && !str_contains(self::UNSAFE, $url[$i])) {
                $out .= $url[$i];
            } else {
                $out .= \sprintf('%%%02X', $byte);
            }
        }

        return $out;
    }

    /**
     * Resolves backslash escapes and entity references in a destination
     * or title string.
     */
    public static function resolve(string $text): string
    {
        $text = preg_replace_callback(
            '/\\\\([!-\/:-@\[-`{-~])/',
            static fn(array $m): string => $m[1],
            $text,
        ) ?? $text;

        return preg_replace_callback(
            '/&(?:([A-Za-z][A-Za-z0-9]{1,47})|#([0-9]{1,7})|#[xX]([0-9A-Fa-f]{1,6}));/',
            static function (array $m): string {
                if ('' !== $m[1]) {
                    return HtmlEntities::decode($m[1]) ?? $m[0];
                }

                return self::utf8('' !== $m[2] ? (int) $m[2] : (int) hexdec($m[3]));
            },
            $text,
        ) ?? $text;
    }

    private static function utf8(int $code): string
    {
        if ($code <= 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
            return "\u{FFFD}";
        }

        if ($code < 0x80) {
            return \chr($code);
        }

        if ($code < 0x800) {
            return \chr(0xC0 | ($code >> 6)) . \chr(0x80 | ($code & 0x3F));
        }

        if ($code < 0x10000) {
            return \chr(0xE0 | ($code >> 12)) . \chr(0x80 | (($code >> 6) & 0x3F)) . \chr(0x80 | ($code & 0x3F));
        }

        return \chr(0xF0 | ($code >> 18)) . \chr(0x80 | (($code >> 12) & 0x3F)) . \chr(0x80 | (($code >> 6) & 0x3F)) . \chr(0x80 | ($code & 0x3F));
    }
}
