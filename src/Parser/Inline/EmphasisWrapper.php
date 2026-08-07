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

namespace Alto\Markdown\Parser\Inline;

/**
 * Applies one matched emphasis pair. The process-emphasis loop
 * (EmphasisProcessor) owns matching; a wrapper owns the representation
 * it wraps in: tape node surgery for the parsing lane, HTML segment
 * patching for the fused emission lane. wrap() consumes $use characters
 * from the inner end of the opener run and the start of the closer run,
 * updating both Delimiter lengths.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface EmphasisWrapper
{
    public function wrap(Delimiter $opener, Delimiter $closer, int $use): void;
}
