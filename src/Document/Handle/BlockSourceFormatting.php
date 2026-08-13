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

namespace Alto\Markdown\Document\Handle;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlockSourceFormatting
{
    public function __construct(private ParsedDocumentModel $model) {}

    public function normalize(string $markdown): string
    {
        $eol = $this->model->source()->dominantEol->value;
        $markdown = (string) preg_replace("/\r\n|\r|\n/", $eol, $markdown);

        return trim($markdown, "\r\n");
    }

    public function insertionReplacement(SourceRange $range, string $markdown): string
    {
        $source = $this->model->source();
        $eol = $source->dominantEol->value;
        $offset = $range->startOffset;
        $logicalStart = $source->hasBom ? 3 : 0;
        $prefix = $offset === $logicalStart
            ? ''
            : str_repeat($eol, \max(0, 2 - $this->lineEndingsBefore($source->bytes, $offset)));
        $suffix = $offset === \strlen($source->bytes)
            ? $eol
            : str_repeat($eol, \max(0, 2 - $this->lineEndingsAfter($source->bytes, $offset)));

        return $prefix . $markdown . $suffix;
    }

    public function exactInsertionReplacement(SourceRange $range, string $markdown): string
    {
        $source = $this->model->source();
        $eol = $source->dominantEol->value;
        $offset = $range->startOffset;
        $logicalStart = $source->hasBom ? 3 : 0;
        $prefix = $offset === $logicalStart
            ? ''
            : str_repeat($eol, \max(0, 2 - $this->lineEndingsBefore($source->bytes, $offset)));
        $trailing = $this->lineEndingsBefore($markdown, \strlen($markdown));
        $suffix = $offset === \strlen($source->bytes)
            ? str_repeat($eol, \max(0, 1 - $trailing))
            : str_repeat($eol, \max(0, 2 - $trailing - $this->lineEndingsAfter($source->bytes, $offset)));

        return $prefix . $markdown . $suffix;
    }

    public function replacement(SourceRange $range, string $markdown): string
    {
        if ('' === $markdown) {
            return '';
        }

        $original = substr(
            $this->model->source()->bytes,
            $range->startOffset,
            $range->endOffset - $range->startOffset,
        );
        $lineEnding = match (true) {
            str_ends_with($original, "\r\n") => "\r\n",
            str_ends_with($original, "\r") => "\r",
            str_ends_with($original, "\n") => "\n",
            default => '',
        };

        return $markdown . $lineEnding;
    }

    private function lineEndingsBefore(string $source, int $offset): int
    {
        $count = 0;

        while ($offset > 0 && $count < 2) {
            if ("\n" === $source[$offset - 1]) {
                --$offset;

                if ($offset > 0 && "\r" === $source[$offset - 1]) {
                    --$offset;
                }

                ++$count;

                continue;
            }

            if ("\r" === $source[$offset - 1]) {
                --$offset;
                ++$count;

                continue;
            }

            break;
        }

        return $count;
    }

    private function lineEndingsAfter(string $source, int $offset): int
    {
        $count = 0;
        $length = \strlen($source);

        while ($offset < $length && $count < 2) {
            if ("\r" === $source[$offset]) {
                ++$offset;

                if ($offset < $length && "\n" === $source[$offset]) {
                    ++$offset;
                }

                ++$count;

                continue;
            }

            if ("\n" === $source[$offset]) {
                ++$offset;
                ++$count;

                continue;
            }

            break;
        }

        return $count;
    }
}
