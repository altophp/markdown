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

use Alto\Markdown\Extension\AbstractExtension;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;
use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\InlineExtensionInterface;
use Alto\Markdown\Extension\NativeBlockOutputExtensionInterface;
use Alto\Markdown\Extension\NodeKindBindings;
use Alto\Markdown\Node\Kind\NodeKind;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class AttributesExtension extends AbstractExtension implements DocumentTransformExtensionInterface, HtmlDecoratorExtensionInterface, InlineExtensionInterface, NativeBlockOutputExtensionInterface
{
    public const string BLOCK_KIND = 'attributes:block';

    /**
     * @param list<NodeKind> $nodeKinds
     */
    public function __construct(
        private readonly AttributesPolicy $policy = new AttributesPolicy(),
        private readonly ?NodeKind $blockKind = null,
        array $nodeKinds = [],
    ) {
        parent::__construct(
            'attributes',
            nodeKindNames: ['block'],
            nodeKinds: $nodeKinds,
        );
    }

    public function blockConstructs(): array
    {
        if (null === $this->blockKind) {
            return [];
        }

        return [
            new AttributesBlockParser(
                $this->blockKind->id,
                new AttributeListParser($this->policy),
            ),
        ];
    }

    public function inlines(): iterable
    {
        $parser = new AttributeListParser($this->policy);
        $output = new AttributesInlineOutput($parser);

        yield new InlineDefinition(
            'inline',
            new AttributesInlineParser($parser),
            $output,
            $output,
        );
    }

    public function documentTransforms(): iterable
    {
        $parser = new AttributeListParser($this->policy);

        yield new DocumentTransformDefinition(
            'catalog',
            static fn(): AttributesTransform => new AttributesTransform($parser),
        );
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::block(new AttributesBlockDecorator(), -2000);
    }

    public function bindNodeKinds(NodeKindBindings $bindings): ExtensionInterface
    {
        return new self(
            $this->policy,
            $bindings->get(self::BLOCK_KIND),
            $bindings->all(),
        );
    }

    public function nativeHtmlBlockRenderers(): array
    {
        return null === $this->blockKind
            ? []
            : [$this->blockKind->id => new AttributesBlockOutput()];
    }

    public function nativeMarkdownBlockPrinters(): array
    {
        return null === $this->blockKind
            ? []
            : [$this->blockKind->id => new AttributesBlockOutput()];
    }
}
