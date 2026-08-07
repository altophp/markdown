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
use Alto\Markdown\Profile\FallbackPolicy;
use Alto\Markdown\Profile\Feature;
use Alto\Markdown\Profile\Profile;
use Alto\Markdown\Render\MarkdownStyle;

/**
 * CommonMark with no callout support, for the negative case.
 */
final class PlainCommonMarkProfile implements Profile
{
    public function name(): string
    {
        return 'commonmark';
    }

    public function extensions(): iterable
    {
        return [new CoreExtension()];
    }

    public function supports(Feature $feature): bool
    {
        return Feature::CommonMark === $feature;
    }

    public function fallbackPolicy(): FallbackPolicy
    {
        return FallbackPolicy::RenderAsCommonMark;
    }

    public function style(): MarkdownStyle
    {
        return new MarkdownStyle();
    }
}
