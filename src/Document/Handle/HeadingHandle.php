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
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Parser\Instrumentation;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HeadingHandle extends BlockNodeHandle implements Heading
{
    public function __construct(ParsedDocumentModel $model, NodeId $id)
    {
        ++Instrumentation::$headingHandles;

        parent::__construct($model, $id);
    }

    public function level(): int
    {
        $this->assertExists();

        return $this->model->headingLevel($this->id()->ordinal);
    }

    public function text(): string
    {
        $this->assertExists();

        return $this->model->plainText($this->id()->ordinal);
    }

    public function rename(string $text): self
    {
        $this->assertExists();
        $this->repin(new HeadingRename($this->model)->apply($this->id(), $text, 'heading'));

        return $this;
    }
}
