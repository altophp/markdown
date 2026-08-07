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

namespace Alto\Markdown\Formatter\Pass;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Formatter\BlockEventCollector;
use Alto\Markdown\Formatter\FormatResult;
use Alto\Markdown\Formatter\FormattingLevel;
use Alto\Markdown\Formatter\FormattingPass;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Traversal\BlockEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class OrderedListDelimiterFormattingPass implements FormattingPass
{
    public function level(): FormattingLevel
    {
        return FormattingLevel::Container;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        $bytes = $model->source()->bytes;
        $events = BlockEventCollector::collect($model);
        [$listDelimiters, $listByItem] = $this->indexLists($bytes, $events);
        $unsafeLists = $this->unsafeAdjacentLists($events, $listDelimiters, $style->orderedListDelimiter);
        $operations = [];

        foreach ($events as $event) {
            if ('list-item' !== $event->kind->name
                || isset($unsafeLists[$listByItem[$event->id->ordinal] ?? -1])
            ) {
                continue;
            }

            $delimiter = $this->delimiterOffset($bytes, $event->range->startOffset);

            if (null === $delimiter || $style->orderedListDelimiter === $bytes[$delimiter]) {
                continue;
            }

            $range = new SourceRange($delimiter, $delimiter + 1);
            $operations[] = new SourcePatchOperation(new SourcePatch($range, $style->orderedListDelimiter, $range, 'format ordered-list delimiter'));
        }

        return new FormatResult($operations);
    }

    private function delimiterOffset(string $bytes, int $contentStart): ?int
    {
        $offset = $contentStart - 1;

        while ($offset >= 0 && (' ' === $bytes[$offset] || "\t" === $bytes[$offset])) {
            --$offset;
        }

        if ($offset < 0 || !str_contains('.)', $bytes[$offset])) {
            return null;
        }

        $digit = $offset - 1;

        while ($digit >= 0 && $bytes[$digit] >= '0' && $bytes[$digit] <= '9') {
            --$digit;
        }

        return $digit === $offset - 1 ? null : $offset;
    }

    /**
     * Index each item to its containing list and read the list's first marker
     * in the same traversal. This avoids rescanning all events for every list.
     *
     * @param list<BlockEvent> $events
     *
     * @return array{array<int, string|null>, array<int, int>}
     */
    private function indexLists(string $bytes, array $events): array
    {
        $listAtDepth = [];
        $delimiters = [];
        $listByItem = [];

        foreach ($events as $event) {
            if ('list' === $event->kind->name) {
                $listAtDepth[$event->depth] = $event;

                continue;
            }

            if ('list-item' !== $event->kind->name) {
                continue;
            }

            $list = $listAtDepth[$event->depth - 1] ?? null;

            if (!$list instanceof BlockEvent
                || $event->range->startOffset < $list->range->startOffset
                || $event->range->endOffset > $list->range->endOffset
            ) {
                continue;
            }

            $listOrdinal = $list->id->ordinal;
            $listByItem[$event->id->ordinal] = $listOrdinal;

            if (!\array_key_exists($listOrdinal, $delimiters)) {
                $offset = $this->delimiterOffset($bytes, $event->range->startOffset);
                $delimiters[$listOrdinal] = null === $offset ? null : $bytes[$offset];
            }
        }

        return [$delimiters, $listByItem];
    }

    /**
     * Rewriting a delimiter at a boundary between adjacent ordered lists
     * would merge them. Keep every item in the list unchanged in that case.
     *
     * @param list<BlockEvent>        $events
     * @param array<int, string|null> $listDelimiters
     *
     * @return array<int, true>
     */
    private function unsafeAdjacentLists(array $events, array $listDelimiters, string $preferred): array
    {
        $byDepth = [];

        foreach ($events as $event) {
            $byDepth[$event->depth][] = $event;
        }

        $unsafe = [];

        foreach ($byDepth as $siblings) {
            $previous = null;

            foreach ($siblings as $event) {
                if ($previous instanceof BlockEvent && 'list' === $previous->kind->name && 'list' === $event->kind->name) {
                    $previousDelimiter = $listDelimiters[$previous->id->ordinal] ?? null;
                    $delimiter = $listDelimiters[$event->id->ordinal] ?? null;

                    if (null !== $previousDelimiter && null !== $delimiter && $previousDelimiter !== $delimiter) {
                        if ($previousDelimiter !== $preferred) {
                            $unsafe[$previous->id->ordinal] = true;
                        }

                        if ($delimiter !== $preferred) {
                            $unsafe[$event->id->ordinal] = true;
                        }
                    }
                }

                $previous = $event;
            }
        }

        return $unsafe;
    }
}
