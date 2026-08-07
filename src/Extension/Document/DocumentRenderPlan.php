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

namespace Alto\Markdown\Extension\Document;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Parser\ParseTapeColumns;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class DocumentRenderPlan
{
    /**
     * @var array<int, int>
     */
    private array $headingLevels = [];

    /**
     * @var array<class-string<DocumentRenderProjection>, DocumentRenderProjection>
     */
    private array $projections = [];

    /**
     * @var array<class-string<DocumentHtmlFinalizer>, DocumentHtmlFinalizer>
     */
    private array $htmlFinalizers = [];

    private ?DocumentRootHtmlLayout $rootHtmlLayout = null;

    public function overrideHeadingLevel(int $ordinal, int $level): void
    {
        $this->headingLevels[$ordinal] = $level;
    }

    public function headingLevel(int $ordinal, int $original): int
    {
        return $this->headingLevels[$ordinal] ?? $original;
    }

    public function provide(DocumentRenderProjection $projection): void
    {
        $this->projections[$projection::class] = $projection;

        if ($projection instanceof DocumentHtmlFinalizer) {
            $this->htmlFinalizers[$projection::class] = $projection;
        }
        if ($projection instanceof DocumentRootHtmlLayout && !$projection->isEmpty()) {
            if (null !== $this->rootHtmlLayout) {
                throw new InvalidExtensionException('Only one document root HTML layout can be active per render.');
            }

            $this->rootHtmlLayout = $projection;
        }
    }

    /**
     * @template T of DocumentRenderProjection
     *
     * @param class-string<T> $type
     *
     * @return T|null
     */
    public function projection(string $type): ?DocumentRenderProjection
    {
        $projection = $this->projections[$type] ?? null;

        return $projection instanceof $type ? $projection : null;
    }

    public function project(ParseTapeColumns $columns): ParseTapeColumns
    {
        if ([] === $this->headingLevels) {
            return $columns;
        }

        $flags = $columns->flags;

        foreach ($this->headingLevels as $ordinal => $level) {
            $flags[$ordinal] = $level;
        }

        return new ParseTapeColumns(
            $columns->kind,
            $columns->parent,
            $columns->firstChild,
            $columns->nextSibling,
            $columns->startOffset,
            $columns->endOffset,
            $columns->generation,
            $flags,
            $columns->payload,
            $columns->extensionInlineNode,
        );
    }

    public function finalizeHtml(string $html): string
    {
        foreach ($this->htmlFinalizers as $finalizer) {
            $html = $finalizer->finalizeHtml($html);
        }

        return $html;
    }

    public function htmlBeforeRootBlock(int $ordinal): string
    {
        return $this->rootHtmlLayout?->htmlBeforeRootBlock($ordinal) ?? '';
    }

    public function hasRootHtmlLayout(): bool
    {
        return null !== $this->rootHtmlLayout;
    }

    public function htmlAfterRootBlocks(): string
    {
        return $this->rootHtmlLayout?->htmlAfterRootBlocks() ?? '';
    }
}
