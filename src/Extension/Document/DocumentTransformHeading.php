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

namespace Alto\Markdown\Extension\Document;

use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DocumentTransformHeading
{
    /**
     * @internal Alto creates heading views for registered transforms
     */
    public function __construct(
        public string $kind,
        public SourceRange $range,
        public int $depth,
        public int $level,
    ) {
    }
}
