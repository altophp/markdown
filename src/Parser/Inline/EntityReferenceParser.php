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
 * HTML entity and numeric character references (CommonMark 0.31.2,
 * "Entity and numeric character references"). A resolved reference is
 * one TEXT node whose payload is the decoded UTF-8; an unresolved form
 * stays literal, so tryParse returns false and the "&" joins the run.
 *
 * Named references use the vendored HtmlEntities table (semicolon-only
 * names). Decimal "&#N;" takes 1-7 digits, hex "&#xN;" takes 1-6; a code
 * point of 0, a surrogate, or one above U+10FFFF decodes to U+FFFD.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class EntityReferenceParser implements InlineConstruct
{
    private const string DIGITS = '0123456789';

    private const string HEX_DIGITS = '0123456789abcdefABCDEF';

    private const string NAME_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const int MAX_CODEPOINT = 0x10FFFF;

    private const string REPLACEMENT = "\u{FFFD}";

    public function triggerBytes(): string
    {
        return '&';
    }

    public function tryParse(InlineScanState $state): bool
    {
        $text = $state->content()->text;
        $length = \strlen($text);
        $offset = $state->offset();

        if ($offset + 1 >= $length) {
            return false;
        }

        if ('#' === $text[$offset + 1]) {
            return $this->parseNumeric($state, $text, $length, $offset);
        }

        return $this->parseNamed($state, $text, $length, $offset);
    }

    private function parseNamed(InlineScanState $state, string $text, int $length, int $offset): bool
    {
        $nameStart = $offset + 1;
        $nameLength = strspn($text, self::NAME_CHARS, $nameStart);

        if (0 === $nameLength) {
            return false;
        }

        $semicolon = $nameStart + $nameLength;

        if ($semicolon >= $length || ';' !== $text[$semicolon]) {
            return false;
        }

        $decoded = HtmlEntities::decode(substr($text, $nameStart, $nameLength));

        if (null === $decoded) {
            return false;
        }

        $state->emit(InlineKind::TEXT, $semicolon + 1, 0, $decoded);

        return true;
    }

    private function parseNumeric(InlineScanState $state, string $text, int $length, int $offset): bool
    {
        $hex = $offset + 2 < $length && ('x' === $text[$offset + 2] || 'X' === $text[$offset + 2]);
        $digitsStart = $hex ? $offset + 3 : $offset + 2;
        $mask = $hex ? self::HEX_DIGITS : self::DIGITS;
        $maxDigits = $hex ? 6 : 7;

        $digitLength = strspn($text, $mask, $digitsStart);

        if ($digitLength < 1 || $digitLength > $maxDigits) {
            return false;
        }

        $semicolon = $digitsStart + $digitLength;

        if ($semicolon >= $length || ';' !== $text[$semicolon]) {
            return false;
        }

        $slice = substr($text, $digitsStart, $digitLength);
        $codepoint = $hex ? (int) hexdec($slice) : (int) $slice;

        $state->emit(InlineKind::TEXT, $semicolon + 1, 0, self::encode($codepoint));

        return true;
    }

    private static function encode(int $codepoint): string
    {
        if ($codepoint <= 0 || $codepoint > self::MAX_CODEPOINT || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
            return self::REPLACEMENT;
        }

        if ($codepoint < 0x80) {
            return \chr($codepoint);
        }

        if ($codepoint < 0x800) {
            return \chr(0xC0 | ($codepoint >> 6)) . \chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint < 0x10000) {
            return \chr(0xE0 | ($codepoint >> 12)) . \chr(0x80 | (($codepoint >> 6) & 0x3F)) . \chr(0x80 | ($codepoint & 0x3F));
        }

        return \chr(0xF0 | ($codepoint >> 18)) . \chr(0x80 | (($codepoint >> 12) & 0x3F)) . \chr(0x80 | (($codepoint >> 6) & 0x3F)) . \chr(0x80 | ($codepoint & 0x3F));
    }
}
