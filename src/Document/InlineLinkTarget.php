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

namespace Alto\Markdown\Document;

use Alto\Markdown\Source\SourceRange;

/**
 * One parsed Markdown link or image eligible for an explicit source rewrite.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineLinkTarget
{
    public function __construct(
        public int $blockOrdinal,
        public int $inlineOrdinal,
        public int $kindId,
        public string $kind,
        public string $destination,
        public ?string $title,
        public SourceRange $range,
        public string $source,
        public bool $inlineSyntax,
    ) {}
}
