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
final class BulletMarkerFormattingPass implements FormattingPass
{
    public function level(): FormattingLevel
    {
        return FormattingLevel::Container;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        $bytes = $model->source()->bytes;
        $events = BlockEventCollector::collect($model);
        [$listMarkers, $listByItem] = $this->indexLists($bytes, $events);
        $unsafeLists = $this->unsafeAdjacentLists($events, $listMarkers);
        $unsafeMarkers = $this->thematicBreakMarkers($bytes, $events, $style->bulletMarker);
        $operations = [];

        foreach ($events as $event) {
            if ('list-item' !== $event->kind->name) {
                continue;
            }

            $marker = $this->markerOffset($bytes, $event->range->startOffset);

            if (null === $marker
                || $style->bulletMarker === $bytes[$marker]
                || isset($unsafeMarkers[$marker])
                || isset($unsafeLists[$listByItem[$event->id->ordinal] ?? -1])
            ) {
                continue;
            }

            $range = new SourceRange($marker, $marker + 1);
            $operations[] = new SourcePatchOperation(new SourcePatch($range, $style->bulletMarker, $range, 'format bullet marker'));
        }

        return new FormatResult($operations);
    }

    private function markerOffset(string $bytes, int $contentStart): ?int
    {
        $offset = $contentStart - 1;

        while ($offset >= 0 && (' ' === $bytes[$offset] || "\t" === $bytes[$offset])) {
            --$offset;
        }

        if ($offset < 0 || !str_contains('-+*', $bytes[$offset])) {
            return null;
        }

        return $offset;
    }

    /**
     * Normalizing nested item markers on one physical line can accidentally
     * turn that line into a thematic break. In that case every marker on the
     * line keeps its original byte.
     *
     * @param list<BlockEvent> $events
     *
     * @return array<int, true>
     */
    private function thematicBreakMarkers(string $bytes, array $events, string $preferredMarker): array
    {
        if (!str_contains('-*', $preferredMarker)) {
            return [];
        }

        $byLine = [];

        foreach ($events as $event) {
            if ('list-item' !== $event->kind->name) {
                continue;
            }

            $marker = $this->markerOffset($bytes, $event->range->startOffset);

            if (null === $marker) {
                continue;
            }

            $lineStart = $this->lineStart($bytes, $marker);
            $byLine[$lineStart][] = $marker;
        }

        $unsafe = [];

        foreach ($byLine as $lineStart => $markers) {
            $lineEnd = $this->lineEnd($bytes, $lineStart);
            $line = substr($bytes, $lineStart, $lineEnd - $lineStart);

            foreach ($markers as $marker) {
                $line[$marker - $lineStart] = $preferredMarker;
            }

            $firstMarker = \min($markers) - $lineStart;
            $suffix = substr($line, $firstMarker);
            $pattern = '/^(?:'.preg_quote($preferredMarker, '/').'[ \t]*){3,}$/D';

            if (1 !== preg_match($pattern, $suffix)) {
                continue;
            }

            foreach ($markers as $marker) {
                $unsafe[$marker] = true;
            }
        }

        return $unsafe;
    }

    private function lineStart(string $bytes, int $offset): int
    {
        while ($offset > 0 && "\n" !== $bytes[$offset - 1] && "\r" !== $bytes[$offset - 1]) {
            --$offset;
        }

        return $offset;
    }

    private function lineEnd(string $bytes, int $offset): int
    {
        return \min($offset + strcspn($bytes, "\r\n", $offset), \strlen($bytes));
    }

    /**
     * Index each item to its containing list and read the list's first marker
     * in the same traversal.
     *
     * @param list<BlockEvent> $events
     *
     * @return array{array<int, string|null>, array<int, int>}
     */
    private function indexLists(string $bytes, array $events): array
    {
        $listAtDepth = [];
        $markers = [];
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

            if (!\array_key_exists($listOrdinal, $markers)) {
                $offset = $this->markerOffset($bytes, $event->range->startOffset);
                $markers[$listOrdinal] = null === $offset ? null : $bytes[$offset];
            }
        }

        return [$markers, $listByItem];
    }

    /**
     * Differently marked adjacent lists are distinct CommonMark blocks. If
     * both markers became equal, reparsing would merge those blocks.
     *
     * @param list<BlockEvent>        $events
     * @param array<int, string|null> $listMarkers
     *
     * @return array<int, true>
     */
    private function unsafeAdjacentLists(array $events, array $listMarkers): array
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
                    $previousMarker = $listMarkers[$previous->id->ordinal] ?? null;
                    $marker = $listMarkers[$event->id->ordinal] ?? null;

                    if (null !== $previousMarker && null !== $marker && $previousMarker !== $marker) {
                        $unsafe[$previous->id->ordinal] = true;
                        $unsafe[$event->id->ordinal] = true;
                    }
                }

                $previous = $event;
            }
        }

        return $unsafe;
    }
}
