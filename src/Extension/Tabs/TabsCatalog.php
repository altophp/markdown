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

use Alto\Markdown\Extension\Document\DocumentRenderProjection;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class TabsCatalog implements DocumentRenderProjection
{
    /**
     * @var array<int, int>
     */
    private array $groupIndexes = [];

    /**
     * @var array<int, list<int>>
     */
    private array $groupItems = [];

    /**
     * @var array<int, array{group: int, index: int}>
     */
    private array $items = [];

    /**
     * @var array<int, string>
     */
    private array $titles = [];

    public function registerGroup(int $groupOffset): void
    {
        $this->groupIndexes[$groupOffset] = \count($this->groupIndexes);
        $this->groupItems[$groupOffset] = [];
    }

    public function registerItem(int $groupOffset, int $itemOffset): void
    {
        if (!isset($this->groupItems[$groupOffset])) {
            throw new \LogicException(\sprintf('Source offset %d is not a tab group.', $groupOffset));
        }

        $index = \count($this->groupItems[$groupOffset]);
        $this->groupItems[$groupOffset][] = $itemOffset;
        $this->items[$itemOffset] = ['group' => $groupOffset, 'index' => $index];
    }

    public function recordTitle(int $itemOffset, string $title): void
    {
        if (!isset($this->items[$itemOffset])) {
            throw new \LogicException(\sprintf('Source offset %d is not a tab item.', $itemOffset));
        }

        $this->titles[$itemOffset] = $title;
    }

    /**
     * @return array{groupId: string, tabId: string, panelId: string, index: int}
     */
    public function item(int $itemOffset): array
    {
        $item = $this->items[$itemOffset]
            ?? throw new \LogicException(\sprintf('Source offset %d is not a tab item.', $itemOffset));
        $groupId = $this->groupId($item['group']);
        $number = $item['index'] + 1;

        return [
            'groupId' => $groupId,
            'tabId' => $groupId . '-tab-' . $number,
            'panelId' => $groupId . '-panel-' . $number,
            'index' => $item['index'],
        ];
    }

    /**
     * @return list<array{title: string, tabId: string, panelId: string, index: int}>
     */
    public function group(int $groupOffset): array
    {
        $items = [];

        foreach ($this->groupItems[$groupOffset] ?? [] as $itemOffset) {
            $item = $this->item($itemOffset);
            $items[] = [
                'title' => $this->titles[$itemOffset]
                    ?? throw new \LogicException(\sprintf('Tab item at source offset %d has not rendered.', $itemOffset)),
                'tabId' => $item['tabId'],
                'panelId' => $item['panelId'],
                'index' => $item['index'],
            ];
        }

        return $items;
    }

    public function groupId(int $groupOffset): string
    {
        $index = $this->groupIndexes[$groupOffset]
            ?? throw new \LogicException(\sprintf('Source offset %d is not a tab group.', $groupOffset));

        return 'markdown-tabs-' . ($index + 1);
    }
}
