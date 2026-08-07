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

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FenceMarkerFormattingPass implements FormattingPass
{
    public function level(): FormattingLevel
    {
        return FormattingLevel::Block;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        $bytes = $model->source()->bytes;
        $operations = [];

        foreach (BlockEventCollector::collect($model) as $event) {
            if ('fenced-code' !== $event->kind->name) {
                continue;
            }

            $openingStart = $event->range->startOffset;
            $sourceMarker = $bytes[$openingStart] ?? '';

            if ($sourceMarker === $style->fenceMarker || !str_contains('`~', $sourceMarker)) {
                continue;
            }

            $openingEnd = $this->runEnd($bytes, $openingStart, $sourceMarker);
            $openingLineEnd = $this->lineEnd($bytes, $openingEnd);
            $info = substr($bytes, $openingEnd, $openingLineEnd - $openingEnd);

            if ('`' === $style->fenceMarker && str_contains($info, '`')) {
                continue;
            }

            $closing = $this->closingFence($bytes, $event->range->endOffset, $sourceMarker, $openingEnd - $openingStart);
            $contentEnd = null === $closing ? $event->range->endOffset : $closing->startOffset;
            $targetLength = \max($openingEnd - $openingStart, $this->longestRun($bytes, $style->fenceMarker, $openingLineEnd, $contentEnd) + 1);
            $openingRange = new SourceRange($openingStart, $openingEnd);
            $replacement = str_repeat($style->fenceMarker, $targetLength);

            $operations[] = new SourcePatchOperation(new SourcePatch($openingRange, $replacement, $openingRange, 'format opening fence marker'));

            if ($closing instanceof SourceRange) {
                $operations[] = new SourcePatchOperation(new SourcePatch($closing, $replacement, $closing, 'format closing fence marker'));
            }
        }

        return new FormatResult($operations);
    }

    private function runEnd(string $bytes, int $start, string $marker): int
    {
        $end = $start;
        $length = \strlen($bytes);

        while ($end < $length && $marker === $bytes[$end]) {
            ++$end;
        }

        return $end;
    }

    private function lineEnd(string $bytes, int $offset): int
    {
        return \min($offset + strcspn($bytes, "\r\n", $offset), \strlen($bytes));
    }

    private function closingFence(string $bytes, int $offset, string $marker, int $minimumLength): ?SourceRange
    {
        $lineEnd = $this->lineEnd($bytes, $offset);
        $candidate = $offset;

        while ($candidate < $lineEnd) {
            if ($marker !== $bytes[$candidate]) {
                ++$candidate;

                continue;
            }

            $end = $this->runEnd($bytes, $candidate, $marker);

            if ($end - $candidate >= $minimumLength && '' === trim(substr($bytes, $end, $lineEnd - $end), " \t")) {
                return new SourceRange($candidate, $end);
            }

            $candidate = $end;
        }

        return null;
    }

    private function longestRun(string $bytes, string $marker, int $start, int $end): int
    {
        $longest = 0;
        $offset = $start;

        while ($offset < $end) {
            if ($marker !== $bytes[$offset]) {
                ++$offset;

                continue;
            }

            $runEnd = $this->runEnd($bytes, $offset, $marker);
            $longest = \max($longest, $runEnd - $offset);
            $offset = $runEnd;
        }

        return $longest;
    }
}
