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

namespace Alto\Markdown\Extension\CodeBlockTitle;

/**
 * Linear scanner for the attribute-shaped suffix of a fenced-code info
 * string. The first word remains the CommonMark language.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CodeBlockTitleInfoParser
{
    private const int MAX_ATTRIBUTES = 64;

    public static function title(string $info, CodeBlockTitlePolicy $policy): ?string
    {
        $length = \strlen($info);
        if ($length > $policy->maxInfoBytes) {
            return null;
        }

        $offset = self::skipToken($info, 0, $length);
        $attributes = [];
        $count = 0;

        while ($offset < $length) {
            $offset = self::skipWhitespace($info, $offset, $length);
            if ($offset >= $length) {
                break;
            }
            if (++$count > self::MAX_ATTRIBUTES) {
                return null;
            }

            $nameStart = $offset;
            while ($offset < $length && self::isNameByte($info[$offset])) {
                ++$offset;
            }
            if ($nameStart === $offset) {
                $offset = self::skipToken($info, $offset, $length);

                continue;
            }

            $name = strtolower(substr($info, $nameStart, $offset - $nameStart));
            $nameEnd = $offset;
            $offset = self::skipHorizontalWhitespace($info, $nameEnd, $length);

            if ($offset >= $length || '=' !== $info[$offset]) {
                $offset = $nameEnd;

                continue;
            }

            ++$offset;
            $offset = self::skipHorizontalWhitespace($info, $offset, $length);
            $parsed = self::value($info, $offset, $length);
            if (null === $parsed) {
                return null;
            }

            [$value, $offset] = $parsed;
            if ($offset < $length && !self::isWhitespace($info[$offset])) {
                return null;
            }
            if (\strlen($value) > $policy->maxTitleBytes
                && \in_array($name, ['title', 'filename'], true)) {
                return null;
            }

            $attributes[$name] = $value;
        }

        return $attributes['title'] ?? $attributes['filename'] ?? null;
    }

    /**
     * @return array{string, int}|null
     */
    private static function value(string $info, int $offset, int $length): ?array
    {
        if ($offset >= $length) {
            return null;
        }

        $quote = $info[$offset];
        if ('"' === $quote || "'" === $quote) {
            $start = ++$offset;
            while ($offset < $length && $quote !== $info[$offset]) {
                ++$offset;
            }
            if ($offset >= $length) {
                return null;
            }

            return [substr($info, $start, $offset - $start), $offset + 1];
        }

        $start = $offset;
        while ($offset < $length && !self::isWhitespace($info[$offset])) {
            ++$offset;
        }

        return $start === $offset ? null : [substr($info, $start, $offset - $start), $offset];
    }

    private static function skipToken(string $info, int $offset, int $length): int
    {
        while ($offset < $length && !self::isWhitespace($info[$offset])) {
            ++$offset;
        }

        return $offset;
    }

    private static function skipWhitespace(string $info, int $offset, int $length): int
    {
        while ($offset < $length && self::isWhitespace($info[$offset])) {
            ++$offset;
        }

        return $offset;
    }

    private static function skipHorizontalWhitespace(string $info, int $offset, int $length): int
    {
        while ($offset < $length && (' ' === $info[$offset] || "\t" === $info[$offset])) {
            ++$offset;
        }

        return $offset;
    }

    private static function isWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\n" === $byte || "\r" === $byte;
    }

    private static function isNameByte(string $byte): bool
    {
        $ord = \ord($byte);

        return ($ord >= 0x41 && $ord <= 0x5A)
            || ($ord >= 0x61 && $ord <= 0x7A)
            || ($ord >= 0x30 && $ord <= 0x39)
            || '_' === $byte
            || '-' === $byte;
    }
}
