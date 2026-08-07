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

namespace Alto\Markdown\Tests\Support;

final readonly class SemanticTreeComparison
{
    private function __construct(
        private bool $equal,
        private string $message,
    ) {
    }

    public static function equal(): self
    {
        return new self(true, 'Trees are equal.');
    }

    public static function different(string $message): self
    {
        return new self(false, $message);
    }

    public function isEqual(): bool
    {
        return $this->equal;
    }

    public function message(): string
    {
        return $this->message;
    }
}
