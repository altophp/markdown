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

namespace Alto\Markdown\Document\Handle;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Exception\StaleHandleException;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
class TapeNodeHandle implements NodeHandle
{
    public function __construct(
        protected readonly ParsedDocumentModel $model,
        private NodeId $id,
    ) {
        ++Instrumentation::$nodeHandles;
    }

    public function id(): NodeId
    {
        return $this->id;
    }

    public function kind(): NodeKind
    {
        $this->assertExists();

        return $this->model->nodeKind($this->id);
    }

    public function range(): SourceRange
    {
        $this->assertExists();

        return $this->model->range($this->id);
    }

    public function exists(): bool
    {
        return $this->model->exists($this->id);
    }

    protected function assertExists(): void
    {
        if (!$this->exists()) {
            throw new StaleHandleException(\sprintf('Node ordinal %d is stale.', $this->id->ordinal));
        }
    }

    /**
     * Re-pins the handle after one of its own mutators bumped the node's
     * generation, so the receiver stays usable instead of going stale against
     * its own edit.
     */
    protected function repin(NodeId $id): void
    {
        $this->id = $id;
    }
}
