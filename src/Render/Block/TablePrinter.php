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

namespace Alto\Markdown\Render\Block;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Render\RenderContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class TablePrinter implements BlockPrinter
{
    public function print(ParsedDocumentModel $model, int $ordinal, RenderContext $context): string
    {
        [$header, $alignments, $body] = $model->tableParts($ordinal);
        $width = \count($header);
        $lines = [
            $this->row($header, $width),
            $this->delimiter($alignments, $width),
        ];

        foreach ($body as $row) {
            $lines[] = $this->row($row, $width);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $cells
     */
    private function row(array $cells, int $width): string
    {
        $out = [];

        for ($index = 0; $index < $width; ++$index) {
            $out[] = $this->cell($cells[$index] ?? '');
        }

        return '| '.implode(' | ', $out).' |';
    }

    /**
     * @param list<string> $alignments
     */
    private function delimiter(array $alignments, int $width): string
    {
        $cells = [];

        for ($index = 0; $index < $width; ++$index) {
            $cells[] = match ($alignments[$index] ?? '') {
                'left' => ':---',
                'right' => '---:',
                'center' => ':---:',
                default => '---',
            };
        }

        return '| '.implode(' | ', $cells).' |';
    }

    private function cell(string $cell): string
    {
        $cell = trim($cell);
        $escaped = '';
        $codeSpanDelimiter = 0;
        $length = \strlen($cell);

        for ($i = 0; $i < $length; ++$i) {
            if ('`' === $cell[$i]) {
                $run = strspn($cell, '`', $i);

                if (0 === $codeSpanDelimiter) {
                    $codeSpanDelimiter = $run;
                } elseif ($run === $codeSpanDelimiter) {
                    $codeSpanDelimiter = 0;
                }

                $escaped .= substr($cell, $i, $run);
                $i += $run - 1;

                continue;
            }

            $escaped .= '|' === $cell[$i] && 0 === $codeSpanDelimiter ? '\\|' : $cell[$i];
        }

        return $escaped;
    }
}
