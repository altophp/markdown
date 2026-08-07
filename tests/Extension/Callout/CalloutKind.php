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

namespace Alto\Markdown\Tests\Extension\Callout;

/**
 * The node kind id the registry hands to `example:callout`.
 *
 * The value is not chosen, it is predicted: DefaultNodeKindRegistry
 * pre-registers 23 core names (ids 1 to 23) and then hands out ids in profile
 * extension order, so a profile of [CoreExtension, CalloutExtension] gives this
 * kind 24. BlockConstruct::kind() must return an int before the compiler has
 * run, and the compiler never tells the extension what it reserved, so the
 * number has to be written down here. The GFM table parser no longer has this
 * defect: its extension injects the compiled `gfm:table` binding.
 *
 * CalloutExtensionTest::testReservedIdMatchesTheHardCodedConstant is the guard
 * every extension author has to write by hand today.
 */
final class CalloutKind
{
    public const int CALLOUT = 24;

    private function __construct()
    {
    }
}
