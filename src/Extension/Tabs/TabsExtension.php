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

namespace Alto\Markdown\Extension\Tabs;

use Alto\Markdown\Extension\AbstractExtension;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\NativeBlockOutputExtensionInterface;
use Alto\Markdown\Extension\NodeKindBindings;
use Alto\Markdown\Node\Kind\NodeKind;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class TabsExtension extends AbstractExtension implements DocumentTransformExtensionInterface, NativeBlockOutputExtensionInterface
{
    public const string GROUP_KIND = 'tabs:group';
    public const string ITEM_KIND = 'tabs:item';

    /**
     * @param list<NodeKind> $nodeKinds
     */
    public function __construct(
        private readonly ?NodeKind $groupKind = null,
        private readonly ?NodeKind $itemKind = null,
        array $nodeKinds = [],
    ) {
        parent::__construct(
            'tabs',
            nodeKindNames: ['group', 'item'],
            nodeKinds: $nodeKinds,
        );
    }

    public function blockConstructs(): array
    {
        if (null === $this->groupKind || null === $this->itemKind) {
            return [];
        }

        return [
            new TabsGroupParser($this->groupKind->id),
            new TabsItemParser($this->groupKind->id, $this->itemKind->id),
        ];
    }

    public function documentTransforms(): iterable
    {
        yield new DocumentTransformDefinition(
            'catalog',
            static fn (): TabsTransform => new TabsTransform(),
        );
    }

    public function bindNodeKinds(NodeKindBindings $bindings): ExtensionInterface
    {
        return new self(
            $bindings->get(self::GROUP_KIND),
            $bindings->get(self::ITEM_KIND),
            $bindings->all(),
        );
    }

    public function nativeHtmlBlockRenderers(): array
    {
        if (null === $this->groupKind || null === $this->itemKind) {
            return [];
        }

        return [
            $this->groupKind->id => new TabsGroupOutput(),
            $this->itemKind->id => new TabsItemOutput(),
        ];
    }

    public function nativeMarkdownBlockPrinters(): array
    {
        if (null === $this->groupKind || null === $this->itemKind) {
            return [];
        }

        $output = new TabsMarkdownOutput();

        return [
            $this->groupKind->id => $output,
            $this->itemKind->id => $output,
        ];
    }
}
