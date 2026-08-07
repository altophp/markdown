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

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SaveOptions
{
    public function __construct(
        public bool $atomic = true,
        public bool $preserveBom = true,
        public bool $preserveEol = true,
        public bool $compareBeforeWrite = false,
        public SymlinkPolicy $symlinks = SymlinkPolicy::Reject,
    ) {
    }
}
