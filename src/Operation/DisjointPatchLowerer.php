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

namespace Alto\Markdown\Operation;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\MarkdownInternalException;
use Alto\Markdown\Exception\PatchConflictException;
use Alto\Markdown\Exception\PatchLoweringException;
use Alto\Markdown\Exception\SourcePatchException;
use Alto\Markdown\Render\MarkdownRenderer;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class DisjointPatchLowerer
{
    public function lower(DocumentModel $model, EditJournal $journal): PatchLoweringResult
    {
        if ($journal->isEmpty()) {
            return new PatchLoweringResult($model->source()->bytes, []);
        }

        $patches = [];
        $ranges = [];
        $fallbackReason = null;

        foreach ($journal->entries() as $entry) {
            if ($entry->operation instanceof MultiPatchOperation) {
                $operationPatches = $entry->operation->toPatches($model);

                if ([] !== $operationPatches) {
                    foreach ($operationPatches as $patch) {
                        $patches[] = $patch;
                        $ranges[] = $patch->affectedRange;
                    }

                    continue;
                }
            }

            $patch = $entry->operation->toPatch($model);

            if (null === $patch) {
                if (!$entry->affectedRange instanceof SourceRange) {
                    throw new PatchLoweringException(\sprintf('Operation "%s" cannot lower to one disjoint source patch and has no affected source range.', $entry->operation->describe()));
                }

                $fallbackReason ??= \sprintf('Operation "%s" cannot lower to one disjoint source patch.', $entry->operation->describe());
                $ranges[] = $entry->affectedRange;

                continue;
            }

            $patches[] = $patch;
            $ranges[] = $patch->affectedRange;
        }

        $patches = $this->sorted($patches);

        if (null === $fallbackReason) {
            $fallbackReason = $this->overlapReason($patches);
        }

        if (null !== $fallbackReason) {
            return $this->fallback($model, $ranges, $fallbackReason);
        }

        return new PatchLoweringResult($this->applySorted($model->source()->bytes, $patches), $patches);
    }

    /**
     * @param list<SourcePatch> $patches
     */
    public function apply(string $source, array $patches): string
    {
        if ([] === $patches) {
            return $source;
        }

        $patches = $this->sorted($patches);

        return $this->applySorted($source, $patches);
    }

    /**
     * @param list<SourcePatch> $patches
     */
    private function applySorted(string $source, array $patches): string
    {
        $this->assertDisjoint($patches, \strlen($source));

        $bytes = '';
        $cursor = 0;

        foreach ($patches as $patch) {
            $bytes .= substr($source, $cursor, $patch->range->startOffset - $cursor);
            $bytes .= $patch->replacement;
            $cursor = $patch->range->endOffset;
        }

        return $bytes.substr($source, $cursor);
    }

    /**
     * @param list<SourceRange> $ranges
     */
    private function fallback(DocumentModel $model, array $ranges, string $reason): PatchLoweringResult
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new PatchLoweringException($reason);
        }

        if ([] === $ranges) {
            throw new PatchLoweringException('Cannot lower edit journal without affected source ranges.');
        }

        $affected = $this->union($ranges);
        $ancestor = $model->fallbackAncestorForRange($affected);
        $range = $model->fallbackPatchRange($ancestor);
        $renderer = new MarkdownRenderer();
        $replacement = $model->isRoot($ancestor)
            ? $this->withOriginalRootFormat($model, $renderer->render($model))
            : $this->withOriginalRangeFormat($model, $range, $renderer->renderBlock($model, $ancestor->ordinal));
        $patch = new SourcePatch($range, $replacement, $affected, $reason);
        $fallback = new PatchLoweringFallback($model->isRoot($ancestor) ? 'root' : 'ancestor', $range, $reason);

        return new PatchLoweringResult($this->applySorted($model->source()->bytes, [$patch]), [$patch], [$fallback]);
    }

    /**
     * @param list<SourceRange> $ranges
     */
    private function union(array $ranges): SourceRange
    {
        $start = null;
        $end = null;

        foreach ($ranges as $range) {
            $start = null === $start ? $range->startOffset : \min($start, $range->startOffset);
            $end = null === $end ? $range->endOffset : \max($end, $range->endOffset);
        }

        if (null === $start || null === $end) {
            throw new MarkdownInternalException('Cannot union an empty source range list.');
        }

        return new SourceRange($start, $end);
    }

    private function withOriginalRangeFormat(ParsedDocumentModel $model, SourceRange $range, string $replacement): string
    {
        $original = substr($model->source()->bytes, $range->startOffset, $range->endOffset - $range->startOffset);
        $eol = $this->rangeLineEnding($original) ?? $model->source()->dominantEol->value;
        $replacement = $this->normalizeLineEndings($replacement, $eol);

        if (
            (str_ends_with($original, "\r\n") || str_ends_with($original, "\r") || str_ends_with($original, "\n"))
            && !str_ends_with($replacement, $eol)
        ) {
            return $replacement.$eol;
        }

        return $replacement;
    }

    private function withOriginalRootFormat(ParsedDocumentModel $model, string $replacement): string
    {
        $source = $model->source();
        $replacement = $this->normalizeLineEndings($replacement, $source->dominantEol->value);

        return ($source->hasBom ? "\xEF\xBB\xBF" : '').$replacement;
    }

    private function rangeLineEnding(string $source): ?string
    {
        if (str_ends_with($source, "\r\n")) {
            return "\r\n";
        }

        if (str_ends_with($source, "\r")) {
            return "\r";
        }

        if (str_ends_with($source, "\n")) {
            return "\n";
        }

        $offset = strcspn($source, "\r\n");

        if ($offset >= \strlen($source)) {
            return null;
        }

        return "\r" === $source[$offset] && "\n" === ($source[$offset + 1] ?? '')
            ? "\r\n"
            : $source[$offset];
    }

    private function normalizeLineEndings(string $replacement, string $eol): string
    {
        $replacement = str_replace(["\r\n", "\r"], "\n", $replacement);

        return "\n" === $eol ? $replacement : str_replace("\n", $eol, $replacement);
    }

    /**
     * @param list<SourcePatch> $patches
     *
     * @return list<SourcePatch>
     */
    private function sorted(array $patches): array
    {
        usort(
            $patches,
            static fn (SourcePatch $left, SourcePatch $right): int => $left->range->startOffset <=> $right->range->startOffset,
        );

        return $patches;
    }

    /**
     * @param list<SourcePatch> $patches
     */
    private function overlapReason(array $patches): ?string
    {
        $previous = null;

        foreach ($patches as $patch) {
            if ($previous instanceof SourcePatch && $patch->range->startOffset < $previous->range->endOffset) {
                return \sprintf(
                    'Overlapping source patches at [%d, %d) and [%d, %d).',
                    $previous->range->startOffset,
                    $previous->range->endOffset,
                    $patch->range->startOffset,
                    $patch->range->endOffset,
                );
            }

            $previous = $patch;
        }

        return null;
    }

    /**
     * @param list<SourcePatch> $patches
     */
    private function assertDisjoint(array $patches, int $sourceLength): void
    {
        $previous = null;

        foreach ($patches as $patch) {
            if ($patch->range->startOffset < 0 || $patch->range->endOffset < $patch->range->startOffset) {
                throw new SourcePatchException('Source patches must use valid original source ranges.');
            }

            if ($patch->range->endOffset > $sourceLength) {
                throw new SourcePatchException('Source patches must not extend beyond the original source length.');
            }

            if ($previous instanceof SourcePatch && $patch->range->startOffset < $previous->range->endOffset) {
                throw new PatchConflictException(\sprintf('Overlapping source patches at [%d, %d) and [%d, %d).', $previous->range->startOffset, $previous->range->endOffset, $patch->range->startOffset, $patch->range->endOffset));
            }

            $previous = $patch;
        }
    }
}
