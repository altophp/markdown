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
 * A profile is the only way to install an extension: nothing else reaches
 * ProfileCompiler. Profile itself is public, but AbstractProfile and
 * CoreExtension are both @internal, so an outside package cannot assemble
 * "CommonMark plus my extension" without reaching into internals.
 */
final class CalloutProfile implements Profile
{
    private readonly MarkdownStyle $style;

    public function __construct()
    {
        $this->style = new MarkdownStyle();
    }

    public function name(): string
    {
        return 'commonmark+callout';
    }

    public function extensions(): iterable
    {
        return [new CoreExtension(), new CalloutExtension()];
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
        return $this->style;
    }
}
