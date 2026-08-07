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

namespace Alto\Markdown\Profile;

use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\FeatureExtensionInterface;
use Alto\Markdown\Render\MarkdownStyle;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract class AbstractProfile implements Profile
{
    private readonly MarkdownStyle $style;

    /**
     * @param list<ExtensionInterface> $extensions
     */
    public function __construct(
        private readonly string $name,
        private readonly array $extensions,
        private readonly FallbackPolicy $fallbackPolicy = FallbackPolicy::RenderAsCommonMark,
        ?MarkdownStyle $style = null,
    ) {
        $this->style = $style ?? new MarkdownStyle();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function extensions(): iterable
    {
        return $this->extensions;
    }

    public function supports(Feature $feature): bool
    {
        foreach ($this->extensions as $extension) {
            if (!$extension instanceof FeatureExtensionInterface) {
                continue;
            }

            foreach ($extension->features() as $provided) {
                if ($provided === $feature) {
                    return true;
                }
            }
        }

        return false;
    }

    public function fallbackPolicy(): FallbackPolicy
    {
        return $this->fallbackPolicy;
    }

    public function style(): MarkdownStyle
    {
        return $this->style;
    }
}
