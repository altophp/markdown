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

namespace Alto\Markdown\Formatter;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FormatterPassMetadata
{
    public function __construct(
        public string $id,
        public string $summary,
        public FormattingLevel $level,
        public ?string $styleField,
    ) {}
}
