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

use Alto\Markdown\Exception\DuplicateExtensionException;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\FeatureExtensionInterface;
use Alto\Markdown\Render\MarkdownStyle;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ComposedProfile implements Profile
{
    private string $name;

    private FallbackPolicy $fallbackPolicy;

    private MarkdownStyle $style;

    /**
     * @var list<ExtensionInterface>
     */
    private array $extensions;

    /**
     * @param list<ExtensionInterface> $additionalExtensions
     */
    public function __construct(
        Profile $base,
        array $additionalExtensions,
    ) {
        $baseExtensions = [...$base->extensions()];
        $extensions = [];
        $names = [];

        foreach ([...$baseExtensions, ...$additionalExtensions] as $extension) {
            $name = $extension->name();

            if (isset($names[$name])) {
                throw new DuplicateExtensionException(\sprintf('Extension "%s" is already installed.', $name));
            }

            $names[$name] = true;
            $extensions[] = $extension;
        }

        $this->extensions = $extensions;
        $this->name = $base->name() . '+' . implode(
            '+',
            array_map(
                static fn(ExtensionInterface $extension): string => $extension->name(),
                $additionalExtensions,
            ),
        );
        $this->fallbackPolicy = $base->fallbackPolicy();
        $this->style = $base->style();
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
