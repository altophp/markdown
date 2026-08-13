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

namespace Alto\Markdown\Operation;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InlineLinkSyntax
{
    public static function validateDestination(string $destination): void
    {
        if (str_contains($destination, "\x00")) {
            throw new InvalidMarkdownArgumentException('Link destination must not contain a null byte.');
        }
    }

    public static function normalizeTitle(?string $title): ?string
    {
        if (null === $title || '' === $title) {
            return null;
        }

        self::validateSingleLine($title, 'Link title');

        return $title;
    }

    public static function validateAltText(string $altText): void
    {
        self::validateSingleLine($altText, 'Image alternative text');
    }

    public static function withLabel(string $label, string $destination, ?string $title): string
    {
        return $label . '(' . self::destination($destination) . self::title($title) . ')';
    }

    public static function image(string $altText, string $destination, ?string $title): string
    {
        return '![' . self::plainText($altText) . '](' . self::destination($destination) . self::title($title) . ')';
    }

    private static function validateSingleLine(string $value, string $name): void
    {
        if (str_contains($value, "\x00") || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidMarkdownArgumentException($name . ' must be a single line without null bytes.');
        }
    }

    private static function destination(string $destination): string
    {
        return str_replace(['\\', ')'], ['\\\\', '\\)'], $destination);
    }

    private static function title(?string $title): string
    {
        if (null === $title) {
            return '';
        }

        return ' "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $title) . '"';
    }

    private static function plainText(string $text): string
    {
        return (string) preg_replace_callback(
            '/[!"#$%&\'()*+,\\.\/:;<=>?@\[\\\\\]\^_`{|}~-]/',
            static fn(array $match): string => '\\' . $match[0],
            $text,
        );
    }

    private function __construct() {}
}
