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
final class BlankLineFormattingPass implements FormattingPass
{
    public function level(): FormattingLevel
    {
        return FormattingLevel::Document;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        if (!$style->normalizeBlankLines) {
            return new FormatResult();
        }

        $bytes = $model->source()->bytes;
        $topLevel = array_values(array_filter(
            BlockEventCollector::collect($model),
            static fn ($event): bool => 1 === $event->depth,
        ));
        $operations = [];

        foreach (array_slice($topLevel, 1) as $event) {
            $lineStart = $this->lineStart($bytes, $event->range->startOffset);
            $lineEndings = $this->precedingBlankLineEndings($bytes, $lineStart);

            foreach (array_slice($lineEndings, 1) as $range) {
                $operations[] = new SourcePatchOperation(new SourcePatch($range, '', $range, 'normalize blank lines between top-level blocks'));
            }
        }

        return new FormatResult($operations);
    }

    private function lineStart(string $bytes, int $offset): int
    {
        $prefix = substr($bytes, 0, $offset);
        $lf = strrpos($prefix, "\n");
        $cr = strrpos($prefix, "\r");
        $newline = \max(false === $lf ? -1 : $lf, false === $cr ? -1 : $cr);

        return $newline + 1;
    }

    /**
     * Return line-ending ranges for blank lines immediately before a line,
     * ordered from nearest to farthest.
     *
     * @return list<SourceRange>
     */
    private function precedingBlankLineEndings(string $bytes, int $lineStart): array
    {
        $ranges = [];
        $cursor = $lineStart;

        while ($cursor > 0) {
            $lineEndingEnd = $cursor;
            $lineEndingStart = "\n" === $bytes[$cursor - 1] ? $cursor - 1 : $cursor;

            if ($lineEndingStart > 0 && "\r" === $bytes[$lineEndingStart - 1]) {
                --$lineEndingStart;
            }

            $previousLineEnd = $lineEndingStart;
            $previousLineStart = $this->lineStart($bytes, $previousLineEnd);
            $content = substr($bytes, $previousLineStart, $previousLineEnd - $previousLineStart);

            if ('' !== trim($content, " \t\r")) {
                break;
            }

            $ranges[] = new SourceRange($lineEndingStart, $lineEndingEnd);
            $cursor = $previousLineStart;
        }

        return $ranges;
    }
}
