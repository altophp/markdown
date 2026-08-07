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

namespace Alto\Markdown\Tests\Conformance;

/**
 * Selects a subset of spec examples by exact section name and/or example
 * number. A null field imposes no constraint, so the empty filter keeps
 * everything.
 */
final readonly class ExampleFilter
{
    public function __construct(
        public ?string $section = null,
        public ?int $example = null,
    ) {
    }

    public function matches(SpecExample $candidate): bool
    {
        if (null !== $this->section && $candidate->section !== $this->section) {
            return false;
        }

        if (null !== $this->example && $candidate->example !== $this->example) {
            return false;
        }

        return true;
    }
}
