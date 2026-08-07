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

namespace Alto\Markdown\Extension;

use Alto\Markdown\Extension\Inline\InlineDefinition;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface InlineExtensionInterface extends ExtensionInterface
{
    /**
     * @return iterable<InlineDefinition>
     */
    public function inlines(): iterable;
}
