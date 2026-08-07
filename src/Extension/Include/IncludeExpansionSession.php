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

namespace Alto\Markdown\Extension\Include;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class IncludeExpansionSession
{
    public int $resources = 0;

    public int $bytes = 0;

    /**
     * @var array<string, true>
     */
    public array $active = [];
}
