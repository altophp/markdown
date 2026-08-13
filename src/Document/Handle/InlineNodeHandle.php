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
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract class InlineNodeHandle implements NodeHandle
{
    public function __construct(
        protected readonly ParsedDocumentModel $model,
        private readonly NodeId $blockId,
        protected readonly int $inlineOrdinal,
    ) {}

    public function id(): NodeId
    {
        return $this->blockId;
    }

    public function kind(): NodeKind
    {
        $this->assertExists();

        return $this->inlineKind();
    }

    public function range(): SourceRange
    {
        $this->assertExists();

        return $this->model->inlineRange($this->blockId->ordinal, $this->inlineOrdinal);
    }

    public function exists(): bool
    {
        return $this->model->exists($this->blockId);
    }

    protected function assertExists(): void
    {
        if (!$this->exists()) {
            throw new StaleHandleException(\sprintf('Inline node in block ordinal %d is stale.', $this->blockId->ordinal));
        }
    }

    abstract protected function inlineKind(): NodeKind;
}
