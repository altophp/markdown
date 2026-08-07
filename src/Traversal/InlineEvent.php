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
use Alto\Markdown\Node\Id\InlineNodeId;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineEvent
{
    public function __construct(
        public DocumentModel $model,
        public NodeId $blockId,
        public InlineNodeId $id,
        public string $kind,
        public SourceRange $range,
    ) {
    }
}
