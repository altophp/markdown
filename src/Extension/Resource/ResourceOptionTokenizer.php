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

namespace Alto\Markdown\Extension\Resource;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ResourceOptionTokenizer
{
    /**
     * @param non-empty-array<string, true> $allowedNames
     *
     * @return array<string, ResourceOptionValue>|null
     */
    public static function tokenize(string $source, array $allowedNames): ?array
    {
        $values = [];
        $length = \strlen($source);
        $offset = self::skipWhitespace($source, 0, $length);

        if ($offset === $length) {
            return $values;
        }

        while ($offset < $length) {
            $nameStart = $offset;

            while ($offset < $length && self::isNameByte($source[$offset])) {
                ++$offset;
            }

            if ($nameStart === $offset) {
                return null;
            }

            $name = substr($source, $nameStart, $offset - $nameStart);
            $offset = self::skipWhitespace($source, $offset, $length);

            if (
                !isset($allowedNames[$name])
                || isset($values[$name])
                || $offset >= $length
                || ':' !== $source[$offset]
            ) {
                return null;
            }

            $offset = self::skipWhitespace($source, $offset + 1, $length);
            if ($offset >= $length) {
                return null;
            }

            if ('"' === $source[$offset]) {
                $parsed = self::quotedValue($source, $offset + 1, $length);
                if (null === $parsed) {
                    return null;
                }

                [$value, $offset] = $parsed;
                $quoted = true;
            } else {
                $valueStart = $offset;

                while ($offset < $length && ',' !== $source[$offset]) {
                    ++$offset;
                }

                $value = trim(substr($source, $valueStart, $offset - $valueStart), " \t");
                $quoted = false;
            }

            if ('' === $value) {
                return null;
            }

            $values[$name] = new ResourceOptionValue($value, $quoted);
            $offset = self::skipWhitespace($source, $offset, $length);

            if ($offset === $length) {
                return $values;
            }

            if (',' !== $source[$offset]) {
                return null;
            }

            $offset = self::skipWhitespace($source, $offset + 1, $length);
            if ($offset === $length) {
                return null;
            }
        }

        return $values;
    }

    /**
     * @return array{string, int}|null
     */
    private static function quotedValue(string $source, int $offset, int $length): ?array
    {
        $value = '';

        while ($offset < $length) {
            $byte = $source[$offset];

            if ('"' === $byte) {
                return [$value, $offset + 1];
            }

            if ('\\' === $byte) {
                ++$offset;

                if ($offset >= $length || ('\\' !== $source[$offset] && '"' !== $source[$offset])) {
                    return null;
                }

                $byte = $source[$offset];
            }

            $value .= $byte;
            ++$offset;
        }

        return null;
    }

    private static function skipWhitespace(string $source, int $offset, int $length): int
    {
        while ($offset < $length && (' ' === $source[$offset] || "\t" === $source[$offset])) {
            ++$offset;
        }

        return $offset;
    }

    private static function isNameByte(string $byte): bool
    {
        $ordinal = \ord($byte);

        return ($ordinal >= 97 && $ordinal <= 122)
            || ($ordinal >= 48 && $ordinal <= 57)
            || '-' === $byte;
    }

    private function __construct()
    {
    }
}
