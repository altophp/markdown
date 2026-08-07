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

use Alto\Markdown\Extension\CommonMark\CoreExtension;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Profile\FallbackPolicy;
use Alto\Markdown\Profile\Feature;
use Alto\Markdown\Profile\Profile;
use Alto\Markdown\Render\MarkdownStyle;

/**
 * A third-party extension installed ahead of GFM, which is the ordering a
 * consumer reaches for when they want their syntax to win a shared trigger
 * byte. It also shifts every node kind id GFM reserves.
 */
final class ShiftedProfile implements Profile
{
    public function name(): string
    {
        return 'callout+gfm';
    }

    public function extensions(): iterable
    {
        return [new CoreExtension(), new CalloutExtension(), new GfmExtension()];
    }

    public function supports(Feature $feature): bool
    {
        return Feature::CommonMark === $feature || Feature::Tables === $feature;
    }

    public function fallbackPolicy(): FallbackPolicy
    {
        return FallbackPolicy::RenderAsCommonMark;
    }

    public function style(): MarkdownStyle
    {
        return MarkdownStyle::gfm();
    }
}
