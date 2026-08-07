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
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlockWrapper
{
    private function __construct(
        private string $firstPrefix,
        private string $continuationPrefix,
    ) {
    }

    public static function quote(): self
    {
        return new self('> ', '> ');
    }

    public static function bullet(string $marker = '-'): self
    {
        if (!\in_array($marker, ['-', '+', '*'], true)) {
            throw new InvalidMarkdownArgumentException('A bullet block wrapper marker must be "-", "+", or "*".');
        }

        return new self($marker.' ', '  ');
    }

    public static function ordered(int $start = 1, string $delimiter = '.'): self
    {
        if ($start < 0 || $start > 999_999_999) {
            throw new InvalidMarkdownArgumentException('An ordered block wrapper start must be between 0 and 999999999.');
        }

        if (!\in_array($delimiter, ['.', ')'], true)) {
            throw new InvalidMarkdownArgumentException('An ordered block wrapper delimiter must be "." or ")".');
        }

        $marker = (string) $start.$delimiter;

        return new self($marker.' ', str_repeat(' ', \strlen($marker) + 1));
    }

    /**
     * @internal
     */
    public function apply(string $markdown): string
    {
        if ('' === $markdown) {
            throw new InvalidMarkdownArgumentException('A block wrapper requires non-empty Markdown.');
        }

        $wrapped = $this->firstPrefix;
        $length = \strlen($markdown);

        for ($offset = 0; $offset < $length; ++$offset) {
            $byte = $markdown[$offset];
            $wrapped .= $byte;

            if ("\r" === $byte && $offset + 1 < $length && "\n" === $markdown[$offset + 1]) {
                $wrapped .= "\n";
                ++$offset;
            } elseif ("\r" !== $byte && "\n" !== $byte) {
                continue;
            }

            if ($offset + 1 < $length) {
                $wrapped .= $this->continuationPrefix;
            }
        }

        return $wrapped;
    }
}
