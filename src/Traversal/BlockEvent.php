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

namespace Alto\Markdown\Traversal;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlockEvent
{
    public function __construct(
        public DocumentModel $model,
        public NodeId $id,
        public NodeKind $kind,
        public SourceRange $range,
        public int $depth,
    ) {}
}
