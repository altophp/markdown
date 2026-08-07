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

use Alto\Markdown\Parser\Block\BlockConstruct;
use Alto\Markdown\Parser\Inline\InlineConstruct;

/**
 * Parser-facing extension declarations compiled away before hot paths run.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface SyntaxExtensionInterface
{
    /**
     * @return list<string>
     */
    public function nodeKindNames(): array;

    /**
     * @return list<BlockConstruct>
     */
    public function blockConstructs(): array;

    /**
     * @return list<InlineConstruct>
     */
    public function inlineConstructs(): array;
}
