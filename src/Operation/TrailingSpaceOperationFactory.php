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

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TrailingSpaceOperationFactory
{
    /**
     * @param list<BlockEvent>  $events
     * @param list<InlineEvent> $inlineEvents
     *
     * @return list<SourcePatchOperation>
     */
    public function create(
        DocumentModel $model,
        array $events,
        array $inlineEvents,
        string $description,
    ): array {
        $bytes = $model->source()->bytes;
        $length = \strlen($bytes);
        $lineStart = 0;
        $operations = [];

        while ($lineStart < $length) {
            $lineEnd = $this->lineEnd($bytes, $lineStart);
            $range = $this->trailingWhitespaceRange($bytes, $lineStart, $lineEnd);

            if (null !== $range && !$this->isOpaquePayload($bytes, $range, $events, $inlineEvents)) {
                $replacement = $this->isParagraphHardBreak($bytes, $range, $events) ? '  ' : '';

                if ($replacement !== substr($bytes, $range->startOffset, $range->endOffset - $range->startOffset)) {
                    $operations[] = new SourcePatchOperation(new SourcePatch($range, $replacement, $range, $description));
                }
            }

            $lineStart = $this->nextLineStart($bytes, $lineEnd);
        }

        return $operations;
    }

    private function lineEnd(string $bytes, int $lineStart): int
    {
        return \min($lineStart + strcspn($bytes, "\r\n", $lineStart), \strlen($bytes));
    }

    private function nextLineStart(string $bytes, int $lineEnd): int
    {
        $length = \strlen($bytes);

        if ($lineEnd < $length && "\r" === $bytes[$lineEnd]) {
            ++$lineEnd;
        }

        if ($lineEnd < $length && "\n" === $bytes[$lineEnd]) {
            ++$lineEnd;
        }

        return $lineEnd;
    }

    private function trailingWhitespaceRange(string $bytes, int $lineStart, int $lineEnd): ?SourceRange
    {
        $start = $lineEnd;

        while ($start > $lineStart && (' ' === $bytes[$start - 1] || "\t" === $bytes[$start - 1])) {
            --$start;
        }

        return $start === $lineEnd ? null : new SourceRange($start, $lineEnd);
    }

    /**
     * @param list<BlockEvent>  $events
     * @param list<InlineEvent> $inlineEvents
     */
    private function isOpaquePayload(string $bytes, SourceRange $range, array $events, array $inlineEvents): bool
    {
        foreach ($events as $event) {
            if (\in_array($event->kind->name, ['indented-code', 'html-block', 'frontmatter:block'], true)
                && $event->range->startOffset <= $range->startOffset
                && $range->endOffset <= $event->range->endOffset
            ) {
                return true;
            }

            if ('fenced-code' !== $event->kind->name) {
                continue;
            }

            $payloadStart = $this->nextLineStart($bytes, $this->lineEnd($bytes, $event->range->startOffset));

            if ($payloadStart <= $range->startOffset && $range->endOffset <= $event->range->endOffset) {
                return true;
            }
        }

        foreach ($inlineEvents as $event) {
            if (\in_array($event->kind, ['code-span', 'html-inline'], true)
                && $event->range->startOffset <= $range->startOffset
                && $range->endOffset <= $event->range->endOffset
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<BlockEvent> $events
     */
    private function isParagraphHardBreak(string $bytes, SourceRange $range, array $events): bool
    {
        $length = $range->endOffset - $range->startOffset;

        if ($length < 2 || str_repeat(' ', $length) !== substr($bytes, $range->startOffset, $length)) {
            return false;
        }

        foreach ($events as $event) {
            if ('paragraph' === $event->kind->name
                && $event->range->startOffset <= $range->startOffset
                && $range->endOffset <= $event->range->endOffset
            ) {
                return true;
            }
        }

        return false;
    }
}
