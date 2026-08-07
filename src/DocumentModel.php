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

namespace Alto\Markdown;

use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Operation\EditJournal;
use Alto\Markdown\Source\SourceDocument;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface DocumentModel
{
    public function generation(): int;

    public function root(): NodeHandle;

    public function node(NodeId $id): NodeHandle;

    public function source(): SourceDocument;

    public function journal(): EditJournal;
}
