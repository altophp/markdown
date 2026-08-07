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
final class ResourceLineSelector
{
    public static function select(string $content, int $start, int $end): string
    {
        $length = \strlen($content);
        $line = 1;
        $selectionStart = 0;
        $selectionStarted = 1 === $start;

        for ($offset = 0; $offset < $length; ++$offset) {
            $byte = $content[$offset];
            if ("\r" !== $byte && "\n" !== $byte) {
                continue;
            }

            $separatorStart = $offset;

            if ("\r" === $byte && $offset + 1 < $length && "\n" === $content[$offset + 1]) {
                ++$offset;
            }

            if ($line === $end) {
                return substr($content, $selectionStart, $separatorStart - $selectionStart);
            }

            ++$line;

            if ($line === $start) {
                $selectionStart = $offset + 1;
                $selectionStarted = true;
            }
        }

        if (!$selectionStarted) {
            return '';
        }

        return substr($content, $selectionStart);
    }

    private function __construct()
    {
    }
}
