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

use Alto\Markdown\Document\PlainText;
use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Render\HtmlRenderSource;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class DocumentTransformContext
{
    /**
     * @var list<DocumentTransformBlock>|null
     */
    private ?array $blocks = null;

    /**
     * @var list<DocumentTransformHeading>|null
     */
    private ?array $headings = null;

    /**
     * @var \WeakMap<DocumentTransformBlock, int>
     */
    private \WeakMap $blockOrdinals;

    /**
     * @var \WeakMap<DocumentTransformHeading, int>
     */
    private \WeakMap $headingOrdinals;

    /**
     * @internal Alto creates contexts for compiled document transforms
     */
    public function __construct(
        private readonly HtmlRenderSource $source,
        private readonly DocumentRenderPlan $plan,
        private readonly bool $completeDocument,
    ) {
        $this->blockOrdinals = new \WeakMap();
        $this->headingOrdinals = new \WeakMap();
    }

    public function rendersCompleteDocument(): bool
    {
        return $this->completeDocument;
    }

    /**
     * @return list<DocumentTransformBlock>
     */
    public function blocks(): array
    {
        if (null !== $this->blocks) {
            return $this->blocks;
        }

        ++Instrumentation::$documentTransformBlockViews;
        $blocks = [];
        $blockOrdinals = $this->blockOrdinals;

        $this->walk(static function (int $ordinal, int $depth, string $kind, SourceRange $range) use (&$blocks, $blockOrdinals): void {
            $block = new DocumentTransformBlock($kind, $range, $depth);
            $blocks[] = $block;
            $blockOrdinals[$block] = $ordinal;
        });

        return $this->blocks = $blocks;
    }

    public function blockState(DocumentTransformBlock $block): BlockState
    {
        return $this->source->htmlTape()->extensionBlockState($this->blockOrdinal($block));
    }

    /**
     * @internal used by compiled document projections
     */
    public function blockOrdinal(DocumentTransformBlock $block): int
    {
        if (!isset($this->blockOrdinals[$block])) {
            throw new InvalidExtensionException('A block must come from the current transform context.');
        }

        return $this->blockOrdinals[$block];
    }

    /**
     * @return list<DocumentTransformHeading>
     */
    public function headings(): array
    {
        if (null !== $this->headings) {
            return $this->headings;
        }

        ++Instrumentation::$documentTransformHeadingViews;
        $headings = [];
        $headingOrdinals = $this->headingOrdinals;
        $tape = $this->source->htmlTape();

        $this->walk(static function (int $ordinal, int $depth, string $kind, SourceRange $range) use (&$headings, $headingOrdinals, $tape): void {
            if ('atx-heading' !== $kind && 'setext-heading' !== $kind) {
                return;
            }

            $heading = new DocumentTransformHeading(
                $kind,
                $range,
                $depth,
                $tape->flags($ordinal),
            );
            $headings[] = $heading;
            $headingOrdinals[$heading] = $ordinal;
        });

        return $this->headings = $headings;
    }

    public function overrideHeadingLevel(DocumentTransformHeading $heading, int $level): void
    {
        if (!isset($this->headingOrdinals[$heading])) {
            throw new InvalidExtensionException('A heading override must target a heading from the current transform context.');
        }

        if ($level < 1 || $level > 6) {
            throw new InvalidExtensionException(\sprintf('Rendered heading level must be between 1 and 6, got %d.', $level));
        }

        $this->plan->overrideHeadingLevel($this->headingOrdinals[$heading], $level);
    }

    public function renderedHeadingLevel(DocumentTransformHeading $heading): int
    {
        $ordinal = $this->headingOrdinal($heading);

        return $this->plan->headingLevel($ordinal, $heading->level);
    }

    public function headingText(DocumentTransformHeading $heading): string
    {
        $ordinal = $this->headingOrdinal($heading);
        $source = $this->source->inlineSourceView($ordinal);
        $view = $this->source->inlineTapeView($ordinal, $source);

        return PlainText::fromInlineTape($view->buffer, $view->tape, 0);
    }

    public function headingSlug(DocumentTransformHeading $heading): string
    {
        return $this->source->headingSlug($this->headingOrdinal($heading));
    }

    /**
     * @internal used by compiled document projections
     */
    public function headingOrdinal(DocumentTransformHeading $heading): int
    {
        if (!isset($this->headingOrdinals[$heading])) {
            throw new InvalidExtensionException('A heading must come from the current transform context.');
        }

        return $this->headingOrdinals[$heading];
    }

    /**
     * @param \Closure(int, int, string, SourceRange): void $visitor
     */
    private function walk(\Closure $visitor): void
    {
        $tape = $this->source->htmlTape();
        $columns = $tape->columns();
        $nodeKinds = $this->source->compiledProfile()->nodeKinds;
        $first = $columns->firstChild[$this->source->htmlRootOrdinal()];

        if (ParseTape::NONE === $first) {
            return;
        }

        /**
         * Each frame owns the next sibling to visit at one depth.
         *
         * @var list<array{int, int}>
         */
        $stack = [[$first, 0]];

        while ([] !== $stack) {
            $last = \count($stack) - 1;
            [$ordinal, $depth] = $stack[$last];

            if (ParseTape::NONE === $ordinal) {
                array_pop($stack);

                continue;
            }

            $stack[$last][0] = $columns->nextSibling[$ordinal];
            $kindId = $columns->kind[$ordinal];
            $kind = match ($kindId) {
                BlockKind::ATX_HEADING => 'atx-heading',
                BlockKind::SETEXT_HEADING => 'setext-heading',
                default => $nodeKinds->get($kindId)->name,
            };
            $visitor(
                $ordinal,
                $depth,
                $kind,
                new SourceRange($columns->startOffset[$ordinal], $columns->endOffset[$ordinal]),
            );

            $child = $columns->firstChild[$ordinal];
            if (ParseTape::NONE !== $child) {
                $stack[] = [$child, $depth + 1];
            }
        }
    }
}
