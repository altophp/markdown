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

namespace Alto\Markdown\Extension\Attributes;

use Alto\Markdown\Extension\Document\DocumentRenderProjection;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class AttributesCatalog implements DocumentRenderProjection
{
    /**
     * @var array<int, array<string, true|string>>
     */
    private array $blocks = [];

    /**
     * @param array<string, true|string> $attributes
     */
    public function addBlock(int $ordinal, array $attributes): void
    {
        $this->blocks[$ordinal] = AttributeSet::merge(
            $this->blocks[$ordinal] ?? [],
            $attributes,
        );
    }

    /**
     * @return array<string, true|string>
     */
    public function block(int $ordinal): array
    {
        return $this->blocks[$ordinal] ?? [];
    }
}
