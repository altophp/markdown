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

namespace Alto\Markdown\Node;

use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface NodeHandle
{
    public function id(): NodeId;

    public function kind(): NodeKind;

    public function range(): SourceRange;

    public function exists(): bool;
}
