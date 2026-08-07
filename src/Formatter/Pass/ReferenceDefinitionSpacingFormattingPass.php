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
final class ReferenceDefinitionSpacingFormattingPass implements FormattingPass
{
    public function level(): FormattingLevel
    {
        return FormattingLevel::Block;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        if (!$style->normalizeReferenceDefinitionSpacing) {
            return new FormatResult();
        }

        $bytes = $model->source()->bytes;
        $operations = [];

        foreach (BlockEventCollector::collect($model) as $event) {
            if ('link-reference-definition' !== $event->kind->name) {
                continue;
            }

            $source = substr($bytes, $event->range->startOffset, $event->range->endOffset - $event->range->startOffset);
            $line = preg_replace('/(?:\\r\\n|\\r|\\n)$/D', '', $source);

            if (null === $line
                || str_contains($line, "\r")
                || str_contains($line, "\n")
                || 1 !== preg_match('/^( {0,3}\\[(?:\\\\.|[^\\[\\]\\r\\n])+\\]:)([ \\t]*)(\\S.*)$/D', $line, $matches, \PREG_OFFSET_CAPTURE)
            ) {
                continue;
            }

            [$spacing, $relativeOffset] = $matches[2];

            if (' ' === $spacing) {
                continue;
            }

            $start = $event->range->startOffset + $relativeOffset;
            $range = new SourceRange($start, $start + \strlen($spacing));
            $operations[] = new SourcePatchOperation(new SourcePatch($range, ' ', $range, 'format reference definition spacing'));
        }

        return new FormatResult($operations);
    }
}
