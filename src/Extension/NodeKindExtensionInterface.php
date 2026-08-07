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

use Alto\Markdown\Node\Kind\NodeKind;

/**
 * @internal compiled node-kind metadata
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface NodeKindExtensionInterface extends ExtensionInterface
{
    /**
     * @return iterable<NodeKind>
     */
    public function nodeKinds(): iterable;
}
