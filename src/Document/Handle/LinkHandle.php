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
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Node\Link;
use Alto\Markdown\Operation\InlineLinkSyntax;
use Alto\Markdown\Operation\SetInlineLinkOperation;
use Alto\Markdown\Parser\Inline\Href;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Instrumentation;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class LinkHandle extends InlineNodeHandle implements Link
{
    public function __construct(ParsedDocumentModel $model, NodeId $blockId, int $inlineOrdinal)
    {
        ++Instrumentation::$linkHandles;

        parent::__construct($model, $blockId, $inlineOrdinal);
    }

    public function text(): string
    {
        $this->assertExists();

        return $this->model->inlinePlainText($this->id()->ordinal, $this->inlineOrdinal);
    }

    public function destination(): string
    {
        $this->assertExists();

        return $this->payloadParts()[0];
    }

    public function titleAttribute(): ?string
    {
        $this->assertExists();
        $title = $this->payloadParts()[1];

        return '' === $title ? null : $title;
    }

    public function setDestination(string $destination): self
    {
        $this->assertExists();
        InlineLinkSyntax::validateDestination($destination);
        $destination = Href::encode($destination);

        if ($destination === $this->destination()) {
            return $this;
        }

        return $this->setAttributes($destination, $this->titleAttribute(), 'set link destination');
    }

    public function setTitleAttribute(?string $title): self
    {
        $this->assertExists();
        $title = InlineLinkSyntax::normalizeTitle($title);

        if ($title === $this->titleAttribute()) {
            return $this;
        }

        return $this->setAttributes($this->destination(), $title, 'set link title');
    }

    /**
     * @return array{string, string}
     */
    private function payloadParts(): array
    {
        return $this->model->inlinePayloadParts($this->id()->ordinal, $this->inlineOrdinal);
    }

    private function setAttributes(string $destination, ?string $title, string $description): self
    {
        $range = $this->model->inlineMutationPatchRange($this->id(), $this->inlineOrdinal, InlineKind::LINK);
        $affectedRange = $range ?? $this->model->range($this->id());
        $label = $this->model->inlineLinkLabelMarkdown($this->id(), $this->inlineOrdinal, InlineKind::LINK);
        $operation = new SetInlineLinkOperation(
            $this->id(),
            $this->inlineOrdinal,
            InlineKind::LINK,
            $destination,
            $title,
            null,
            $description,
            $range,
            InlineLinkSyntax::withLabel($label, $destination, $title),
        );
        $operation->apply($this->model);
        $this->model->journal()->record($operation, $affectedRange);

        return new self($this->model, $this->model->currentNodeId($this->id()->ordinal), $this->inlineOrdinal);
    }

    protected function inlineKind(): NodeKind
    {
        return $this->model->coreNodeKind('link');
    }
}
