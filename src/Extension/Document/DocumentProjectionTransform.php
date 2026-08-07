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

/**
 * A transform that publishes typed semantic data to planned block renderers.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface DocumentProjectionTransform extends DocumentTransform
{
    public function projection(): DocumentRenderProjection;
}
