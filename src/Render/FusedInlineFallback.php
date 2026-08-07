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

namespace Alto\Markdown\Render;

/**
 * Control-flow signal aborting one fused inline render: the block needs
 * the tape path (a construct the flat segment stream cannot reproduce).
 * Never crosses the public API: FusedInlineRenderer throws it and catches
 * it within the same render call.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FusedInlineFallback extends \Exception
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Fused inline rendering fell back: '.$reason);
    }
}
