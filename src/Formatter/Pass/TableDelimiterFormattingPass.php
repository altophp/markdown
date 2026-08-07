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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Formatter\BlockEventCollector;
use Alto\Markdown\Formatter\FormatResult;
use Alto\Markdown\Formatter\FormattingLevel;
use Alto\Markdown\Formatter\FormattingPass;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Parser\Block\GfmTableParser;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class TableDelimiterFormattingPass implements FormattingPass
{
    public function level(): FormattingLevel
    {
        return FormattingLevel::Block;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        if (!$style->normalizeTableDelimiters || !$model instanceof ParsedDocumentModel) {
            return new FormatResult();
        }

        $bytes = $model->source()->bytes;
        $operations = [];

        foreach (BlockEventCollector::collect($model) as $event) {
            if ('gfm:table' !== $event->kind->name || 1 !== $event->depth) {
                continue;
            }

            $payload = $model->blockPayload($event->id->ordinal);
            $separator = null === $payload ? false : strpos($payload, '|');
            $headerPair = false === $separator ? null : substr($payload, 0, $separator);

            if (null === $headerPair || 1 !== preg_match('/^(\\d+):(\\d+)$/D', $headerPair, $pair)) {
                continue;
            }

            $delimiterStart = $this->afterLineEnding($bytes, (int) $pair[2]);

            if (null === $delimiterStart) {
                continue;
            }

            $lineEnd = $this->lineEnd($bytes, $delimiterStart);
            $contentStart = $delimiterStart + strspn($bytes, ' ', $delimiterStart, min(3, $lineEnd - $delimiterStart));
            $rawLine = substr($bytes, $contentStart, $lineEnd - $contentStart);
            $line = rtrim($rawLine, " \t");
            $alignments = GfmTableParser::delimiterAlignments($line);

            if (null === $alignments) {
                continue;
            }

            $trimmed = trim($line);
            $leadingPipe = str_starts_with($trimmed, '|');
            $trailingPipe = str_ends_with($trimmed, '|');
            $cells = array_map(
                static fn (string $alignment): string => match ($alignment) {
                    'left' => ':---',
                    'center' => ':---:',
                    'right' => '---:',
                    default => '---',
                },
                $alignments,
            );
            $replacement = ($leadingPipe ? '| ' : '').implode(' | ', $cells).($trailingPipe ? ' |' : '');

            if ($line === $replacement) {
                continue;
            }

            // Trailing whitespace belongs to NoTrailingSpacesFormattingPass.
            // Keeping the ranges disjoint lets both source-level decisions
            // compose in one formatting operation.
            $range = new SourceRange($contentStart, $contentStart + \strlen($line));
            $operations[] = new SourcePatchOperation(new SourcePatch($range, $replacement, $range, 'format table delimiter row'));
        }

        return new FormatResult($operations);
    }

    private function afterLineEnding(string $bytes, int $offset): ?int
    {
        if ($offset >= \strlen($bytes)) {
            return null;
        }

        if ("\r" === $bytes[$offset] && "\n" === ($bytes[$offset + 1] ?? null)) {
            return $offset + 2;
        }

        return "\r" === $bytes[$offset] || "\n" === $bytes[$offset] ? $offset + 1 : null;
    }

    private function lineEnd(string $bytes, int $offset): int
    {
        return \min($offset + strcspn($bytes, "\r\n", $offset), \strlen($bytes));
    }
}
