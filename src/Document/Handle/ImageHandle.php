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
use Alto\Markdown\Node\Image;
use Alto\Markdown\Node\Kind\NodeKind;
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
final class ImageHandle extends InlineNodeHandle implements Image
{
    public function __construct(ParsedDocumentModel $model, NodeId $blockId, int $inlineOrdinal)
    {
        ++Instrumentation::$imageHandles;

        parent::__construct($model, $blockId, $inlineOrdinal);
    }

    public function altText(): string
    {
        $this->assertExists();

        return $this->model->inlineImageAltText($this->id()->ordinal, $this->inlineOrdinal);
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

        return $this->setAttributes($destination, $this->altText(), 'set image destination');
    }

    public function setAltText(string $altText): self
    {
        $this->assertExists();
        InlineLinkSyntax::validateAltText($altText);

        if ($altText === $this->altText()) {
            return $this;
        }

        return $this->setAttributes($this->destination(), $altText, 'set image alternative text', replaceLabel: true);
    }

    /**
     * @return array{string, string}
     */
    private function payloadParts(): array
    {
        return $this->model->inlinePayloadParts($this->id()->ordinal, $this->inlineOrdinal);
    }

    private function setAttributes(string $destination, string $altText, string $description, bool $replaceLabel = false): self
    {
        $range = $this->model->inlineMutationPatchRange($this->id(), $this->inlineOrdinal, InlineKind::IMAGE);
        $affectedRange = $range ?? $this->model->range($this->id());
        $title = $this->titleAttribute();
        $replacement = $replaceLabel
            ? InlineLinkSyntax::image($altText, $destination, $title)
            : InlineLinkSyntax::withLabel(
                $this->model->inlineLinkLabelMarkdown($this->id(), $this->inlineOrdinal, InlineKind::IMAGE),
                $destination,
                $title,
            );
        $operation = new SetInlineLinkOperation(
            $this->id(),
            $this->inlineOrdinal,
            InlineKind::IMAGE,
            $destination,
            $title,
            $replaceLabel ? $altText : null,
            $description,
            $range,
            $replacement,
        );
        $operation->apply($this->model);
        $this->model->journal()->record($operation, $affectedRange);

        return new self($this->model, $this->model->currentNodeId($this->id()->ordinal), $this->inlineOrdinal);
    }

    protected function inlineKind(): NodeKind
    {
        return $this->model->coreNodeKind('image');
    }
}
