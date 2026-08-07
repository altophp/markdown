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
final class AtxHeadingSpacingFormattingPass implements FormattingPass
{
    public function level(): FormattingLevel
    {
        return FormattingLevel::Block;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        if (!$style->normalizeHeadingSpacing) {
            return new FormatResult();
        }

        $bytes = $model->source()->bytes;
        $operations = [];

        foreach (BlockEventCollector::collect($model) as $event) {
            if ('atx-heading' !== $event->kind->name) {
                continue;
            }

            $markerEnd = $event->range->startOffset;
            $lineEnd = $this->lineEnd($bytes, $markerEnd);

            while ($markerEnd < $lineEnd && '#' === $bytes[$markerEnd]) {
                ++$markerEnd;
            }

            $contentStart = $markerEnd;

            while ($contentStart < $lineEnd && (' ' === $bytes[$contentStart] || "\t" === $bytes[$contentStart])) {
                ++$contentStart;
            }

            if ($contentStart === $lineEnd || 1 === $contentStart - $markerEnd && ' ' === $bytes[$markerEnd]) {
                continue;
            }

            $range = new SourceRange($markerEnd, $contentStart);
            $operations[] = new SourcePatchOperation(new SourcePatch($range, ' ', $range, 'format ATX heading spacing'));
        }

        return new FormatResult($operations);
    }

    private function lineEnd(string $bytes, int $offset): int
    {
        $end = strcspn($bytes, "\r\n", $offset) + $offset;

        return \min($end, \strlen($bytes));
    }
}
