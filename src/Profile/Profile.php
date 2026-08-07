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
use Alto\Markdown\Render\MarkdownStyle;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface Profile
{
    public function name(): string;

    /**
     * @return iterable<ExtensionInterface>
     */
    public function extensions(): iterable;

    public function supports(Feature $feature): bool;

    public function fallbackPolicy(): FallbackPolicy;

    public function style(): MarkdownStyle;
}
