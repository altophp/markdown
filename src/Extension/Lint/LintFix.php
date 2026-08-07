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

namespace Alto\Markdown\Extension\Lint;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LintFix
{
    private function __construct(
        public SourceRange $range,
        public string $replacement,
        public string $description,
    ) {
        if ('' === trim($description)) {
            throw new InvalidExtensionException('Lint fix description must not be empty.');
        }
    }

    public static function replace(SourceRange $range, string $replacement, string $description): self
    {
        return new self($range, $replacement, $description);
    }

    public static function insert(int $offset, string $bytes, string $description): self
    {
        return new self(new SourceRange($offset, $offset), $bytes, $description);
    }

    public static function delete(SourceRange $range, string $description): self
    {
        return new self($range, '', $description);
    }
}
