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

namespace Alto\Markdown\Render;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Parser\ParseTape;

/**
 * Render-time scratch shared by the Markdown block printers.
 *
 * Shallow documents print through the recursive path: a printer asks for its
 * children and {@see renderChildrenWithSeparator} recurses back into the
 * renderer. Past {@see MarkdownRenderer::STACK_LIMIT} the renderer drives the
 * walk iteratively instead and stashes each container's already-printed
 * children here; the same printers then read them through {@see renderChildren}
 * or {@see renderListItem} without recursing.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class RenderContext
{
    private readonly MarkdownStyle $style;

    private int $depth = 0;

    /**
     * Printed children keyed by container ordinal, populated only while the
     * iterative driver finalizes that container.
     *
     * @var array<int, list<string>>
     */
    private array $childStash = [];

    public function __construct(
        private readonly MarkdownRenderer $renderer,
        ?RenderOptions $options,
        private readonly ?int $attachedBlockKind = null,
    ) {
        $this->style = null === $options || null === $options->style ? new MarkdownStyle() : $options->style;
    }

    public function style(): MarkdownStyle
    {
        return $this->style;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function enter(): void
    {
        ++$this->depth;
    }

    public function leave(): void
    {
        --$this->depth;
    }

    public function renderInlines(ParsedDocumentModel $model, int $ordinal): string
    {
        return new InlineRenderer($this->style)->renderBlock($model, $ordinal);
    }

    /**
     * @param list<string> $parts
     */
    public function stashChildren(int $ordinal, array $parts): void
    {
        $this->childStash[$ordinal] = $parts;
    }

    public function releaseChildren(int $ordinal): void
    {
        unset($this->childStash[$ordinal]);
    }

    public function renderChildren(ParsedDocumentModel $model, int $ordinal): string
    {
        if (isset($this->childStash[$ordinal])) {
            return $this->joinStashed($model, $ordinal, "\n\n");
        }

        return $this->renderChildrenWithSeparator($model, $ordinal, "\n\n");
    }

    public function renderChildrenCompact(ParsedDocumentModel $model, int $ordinal): string
    {
        if (isset($this->childStash[$ordinal])) {
            return $this->joinStashed($model, $ordinal, "\n");
        }

        return $this->renderChildrenWithSeparator($model, $ordinal, "\n");
    }

    public function renderListItem(ParsedDocumentModel $model, int $ordinal, string $marker, bool $loose): string
    {
        $separator = $loose ? "\n\n" : "\n";
        $children = isset($this->childStash[$ordinal])
            ? $this->joinStashed($model, $ordinal, $separator)
            : $this->renderChildrenWithSeparator($model, $ordinal, $separator);
        $task = match ($model->taskListState($ordinal)) {
            'unchecked' => '[ ] ',
            'checked' => '[x] ',
            default => '',
        };

        if ('' === $children) {
            return rtrim($marker.' '.$task);
        }

        $lines = explode("\n", $children);
        $first = array_shift($lines) ?? '';
        $prefix = $marker.' '.$task;
        $indent = str_repeat(' ', \strlen($prefix));
        $rendered = $prefix.$first;

        foreach ($lines as $line) {
            $rendered .= "\n".('' === $line ? '' : $indent.$line);
        }

        return $rendered;
    }

    private function renderChildrenWithSeparator(ParsedDocumentModel $model, int $ordinal, string $separator): string
    {
        $rendered = [];
        $ordinals = [];
        $child = $model->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $rendered[] = $this->renderer->renderNode($model, $child, $this);
            $ordinals[] = $child;
            $child = $model->nextSiblingOrdinal($child);
        }

        return $this->join($model, $ordinals, $rendered, $separator);
    }

    private function joinStashed(ParsedDocumentModel $model, int $ordinal, string $separator): string
    {
        $parts = $this->childStash[$ordinal] ?? [];
        $ordinals = [];
        $child = $model->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $ordinals[] = $child;
            $child = $model->nextSiblingOrdinal($child);
        }

        return $this->join($model, $ordinals, $parts, $separator);
    }

    /**
     * @param list<int>    $ordinals
     * @param list<string> $parts
     */
    private function join(ParsedDocumentModel $model, array $ordinals, array $parts, string $separator): string
    {
        $result = '';
        $previous = null;

        foreach ($parts as $index => $part) {
            if ('' === $part) {
                continue;
            }

            $current = $ordinals[$index] ?? null;
            if (null !== $previous && null !== $current) {
                $result .= $this->separatorBetween($model, $previous, $current, $separator);
            }

            $result .= $part;
            $previous = $current;
        }

        return $result;
    }

    private function separatorBetween(ParsedDocumentModel $model, int $previous, int $current, string $default): string
    {
        if (null === $this->attachedBlockKind) {
            return $default;
        }

        if ($this->attachedBlockKind === $model->renderNodeKindId($previous)
            && 'next' === $model->extensionBlockState($previous)->string('target')
        ) {
            return "\n";
        }

        if ($this->attachedBlockKind === $model->renderNodeKindId($current)
            && 'previous' === $model->extensionBlockState($current)->string('target')
        ) {
            return "\n";
        }

        return $default;
    }
}
