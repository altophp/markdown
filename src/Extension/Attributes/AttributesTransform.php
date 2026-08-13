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

namespace Alto\Markdown\Extension\Attributes;

use Alto\Markdown\Extension\Document\DocumentProjectionTransform;
use Alto\Markdown\Extension\Document\DocumentRenderProjection;
use Alto\Markdown\Extension\Document\DocumentTransformBlock;
use Alto\Markdown\Extension\Document\DocumentTransformContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class AttributesTransform implements DocumentProjectionTransform
{
    private AttributesCatalog $catalog;

    /**
     * @var array<int, DocumentTransformBlock>
     */
    private array $ancestors = [];

    /**
     * @var array<string, int|null>
     */
    private array $previous = [];

    /**
     * @var array<string, array<string, true|string>>
     */
    private array $pending = [];

    public function __construct(private readonly AttributeListParser $parser)
    {
        $this->catalog = new AttributesCatalog();
    }

    public function transform(DocumentTransformContext $context): void
    {
        $this->catalog = new AttributesCatalog();
        $this->ancestors = [];
        $this->previous = [];
        $this->pending = [];

        foreach ($context->blocks() as $block) {
            $this->ancestors = \array_slice($this->ancestors, 0, $block->depth);
            $parent = $block->depth > 0 ? ($this->ancestors[$block->depth - 1] ?? null) : null;
            $key = $block->depth . ':' . (null === $parent ? -1 : $context->blockOrdinal($parent));

            if (AttributesExtension::BLOCK_KIND === $block->kind) {
                $attributes = $this->attributes($context, $block);
                $target = $context->blockState($block)->string('target');

                if ('previous' === $target) {
                    $previous = $this->previous[$key] ?? null;
                    if (null !== $previous) {
                        $this->catalog->addBlock($previous, $attributes);
                    }
                } else {
                    $this->pending[$key] = AttributeSet::merge($this->pending[$key] ?? [], $attributes);
                }

                $this->ancestors[$block->depth] = $block;

                continue;
            }

            if (isset($this->pending[$key])) {
                if ($this->targetable($block->kind)) {
                    $this->catalog->addBlock($context->blockOrdinal($block), $this->pending[$key]);
                }
                unset($this->pending[$key]);
            }

            $this->previous[$key] = $this->targetable($block->kind)
                ? $context->blockOrdinal($block)
                : null;
            $this->ancestors[$block->depth] = $block;
        }
    }

    public function projection(): DocumentRenderProjection
    {
        return $this->catalog;
    }

    /**
     * @return array<string, true|string>
     */
    private function attributes(DocumentTransformContext $context, DocumentTransformBlock $block): array
    {
        $attributes = [];

        foreach (explode("\n", $context->blockState($block)->string('source')) as $source) {
            $parsed = $this->parser->parseWhole($source)
                ?? throw new \LogicException('Parsed attribute block contains invalid syntax.');
            $attributes = AttributeSet::merge($attributes, $parsed->attributes);
        }

        return $attributes;
    }

    private function targetable(string $kind): bool
    {
        return !\in_array($kind, [
            AttributesExtension::BLOCK_KIND,
            'front-matter',
            'html-block',
            'link-reference-definition',
        ], true);
    }
}
