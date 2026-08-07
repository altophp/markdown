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

namespace Alto\Markdown\Parser\Block;

use Alto\Markdown\Parser\Inline\Href;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ReadOnlyParseTape;
use Alto\Markdown\Source\SourceRange;

/**
 * Interprets source-bearing payloads stored on the block tape.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlockContentReader
{
    public function __construct(
        private SourceBuffer $buffer,
        private ReadOnlyParseTape $tape,
    ) {
    }

    public function inlineMarkdownSource(int $ordinal): string
    {
        ++Instrumentation::$inlineMarkdownReconstructions;
        $parts = [];

        foreach ($this->contentPairs($ordinal) as [$start, $end]) {
            $parts[] = $this->buffer->substring($start, $end);
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<array{int, int, int}>
     */
    public function contentPairs(int $ordinal): array
    {
        $payload = $this->tape->payload($ordinal);

        if (null === $payload || '' === $payload) {
            return [[$this->tape->startOffset($ordinal), $this->tape->endOffset($ordinal), 0]];
        }

        $pairs = [];

        foreach (explode(';', $payload) as $pair) {
            $parts = explode(':', $pair, 4);
            $pairs[] = [(int) $parts[0], (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0)];
        }

        return $pairs;
    }

    public function codeBlockLanguage(int $ordinal): ?string
    {
        if (BlockKind::FENCED_CODE !== $this->tape->kindId($ordinal)) {
            return null;
        }

        $info = $this->fencedInfo($ordinal);

        if ('' === $info) {
            return null;
        }

        $language = explode(' ', $this->decodeInfo($info))[0];

        return '' === $language ? null : $language;
    }

    public function codeBlockCode(int $ordinal): string
    {
        $kind = $this->tape->kindId($ordinal);
        ++Instrumentation::$codeBlockCodeReads;

        return $this->readCode($ordinal, $kind);
    }

    /**
     * @return array{string|null, string, string}
     */
    public function codeBlockParts(int $ordinal): array
    {
        $kind = $this->tape->kindId($ordinal);
        $info = BlockKind::FENCED_CODE === $kind
            ? $this->decodeInfo($this->fencedInfo($ordinal))
            : '';
        $language = '' === $info ? null : explode(' ', $info)[0];

        ++Instrumentation::$codeBlockCodeReads;

        return [$language, $info, $this->readCode($ordinal, $kind)];
    }

    private function readCode(int $ordinal, int $kind): string
    {
        if (BlockKind::FENCED_CODE === $kind) {
            $compact = $this->compactFencedCode($ordinal);

            if (null !== $compact) {
                return $compact;
            }
        }

        $code = '';

        if (BlockKind::FENCED_CODE === $kind) {
            $indent = $this->tape->flags($ordinal);

            foreach ($this->codePairs($ordinal) as [$start, $end, $pad]) {
                $code .= $this->stripFenceIndent($this->buffer->substring($start, $end), $indent, $pad)."\n";
            }

            return $code;
        }

        foreach ($this->codePairs($ordinal) as [$start, $end, $pad, $column]) {
            $code .= $this->stripColumns($this->buffer->substring($start, $end), 4, $pad, $column)."\n";
        }

        return $code;
    }

    public function codeBlockFenceIsClosed(int $ordinal): bool
    {
        if (BlockKind::FENCED_CODE !== $this->tape->kindId($ordinal)) {
            return false;
        }

        $payload = $this->tape->payload($ordinal) ?? '';
        $separator = strpos($payload, '|');

        return false !== $separator && str_ends_with(substr($payload, 0, $separator), '!');
    }

    /**
     * The verbatim source an HTML block contributes, container markers
     * excluded.
     *
     * At document root the block is an opaque leaf whose member lines are
     * contiguous in the input, so one span read reproduces it (RP.8). Inside
     * a block quote or a list item the parser instead recorded one range per
     * line, each already past the container prefix, so those ranges are
     * rejoined here. Reading the span in that case would carry the "> "
     * marker or the item indent of every continuation line into the output
     * (CommonMark 0.31.2 example 174).
     */
    public function htmlBlockSource(int $ordinal): string
    {
        $payload = $this->tape->payload($ordinal);

        if (null === $payload || '' === $payload
            || BlockKind::DOCUMENT === $this->tape->kindId($this->tape->parentOrdinal($ordinal))) {
            return $this->buffer->substring($this->tape->startOffset($ordinal), $this->tape->endOffset($ordinal));
        }

        $bytes = $this->buffer->bytes;
        $html = '';
        $separator = '';

        foreach (explode(';', $payload) as $pair) {
            $parts = explode(':', $pair, 4);
            $start = (int) $parts[0];
            $pad = (int) ($parts[2] ?? 0);
            $html .= $separator;

            if ($pad > 0) {
                $html .= str_repeat(' ', $pad);
            }

            $html .= substr($bytes, $start, (int) ($parts[1] ?? 0) - $start);
            $separator = "\n";
        }

        return $html."\n";
    }

    private function compactFencedCode(int $ordinal): ?string
    {
        $payload = $this->tape->payload($ordinal) ?? '';

        if (!str_starts_with($payload, '@')) {
            return null;
        }

        $separator = strpos($payload, '|');
        $span = false === $separator ? substr($payload, 1) : substr($payload, 1, $separator - 1);

        if (str_ends_with($span, '!')) {
            $span = substr($span, 0, -1);
        }

        [$start, $end, $appendLf] = array_pad(array_map('intval', explode(':', $span, 3)), 3, 0);
        $code = $this->buffer->substring($start, $end);

        if (str_contains($code, "\r")) {
            $code = str_replace(["\r\n", "\r"], "\n", $code);
        }

        return $code.(1 === $appendLf ? "\n" : '');
    }

    /**
     * @return array{list<string>, list<string>, list<list<string>>}
     */
    public function tableParts(int $ordinal): array
    {
        $payload = $this->tape->payload($ordinal) ?? '||';
        [$headerPart, $alignmentPart, $bodyPart] = array_pad(explode('|', $payload, 3), 3, '');
        $header = GfmTableParser::splitRow($this->lineFromPair($headerPart));
        $alignments = '' === $alignmentPart ? [] : explode(',', $alignmentPart);
        $body = [];

        if ('' !== $bodyPart) {
            foreach (explode(';', $bodyPart) as $pair) {
                $body[] = GfmTableParser::splitRow($this->lineFromPair($pair));
            }
        }

        return [$header, $alignments, $body];
    }

    /**
     * @return list<array{list<string>, SourceRange}>
     */
    public function tableBodyRows(int $ordinal): array
    {
        $payload = $this->tape->payload($ordinal) ?? '||';
        $bodyPart = array_pad(explode('|', $payload, 3), 3, '')[2];
        $rows = [];

        if ('' === $bodyPart) {
            return [];
        }

        foreach (explode(';', $bodyPart) as $pair) {
            $parts = explode(':', $pair, 3);
            $start = (int) $parts[0];
            $end = (int) ($parts[1] ?? $start);
            $rows[] = [
                GfmTableParser::splitRow($this->buffer->substring($start, $end)),
                new SourceRange($start, $end),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{int, int, int, int}>
     */
    private function codePairs(int $ordinal): array
    {
        $payload = $this->tape->payload($ordinal) ?? '';
        $pairsPart = $payload;

        if (BlockKind::FENCED_CODE === $this->tape->kindId($ordinal)) {
            $separator = strpos($payload, '|');
            $pairsPart = false === $separator ? '' : substr($payload, 0, $separator);

            if (str_ends_with($pairsPart, '!')) {
                $pairsPart = substr($pairsPart, 0, -1);
            }
        }

        if ('' === $pairsPart) {
            return [];
        }

        $pairs = [];

        foreach (explode(';', $pairsPart) as $pair) {
            $parts = array_map('intval', explode(':', $pair));
            $pairs[] = [$parts[0], $parts[1] ?? $parts[0], $parts[2] ?? 0, $parts[3] ?? 0];
        }

        return $pairs;
    }

    private function fencedInfo(int $ordinal): string
    {
        $payload = $this->tape->payload($ordinal) ?? '|';
        $separator = strpos($payload, '|');

        return false === $separator ? '' : substr($payload, $separator + 1);
    }

    /**
     * Removes up to $columns virtual columns of indentation, measured from the
     * container's content indentation rather than from column zero: an indented
     * code line inside a list item or block quote strips exactly four columns
     * past where the container's content starts.
     *
     * $pad is the count of virtual spaces owed by a tab the container prefix
     * only partially consumed; they are stripped first and any survivor renders
     * as a real space. $startColumn is the absolute column of the first byte, so
     * interior tabs expand to the right multiple of four, and a tab straddling
     * the four-column goal contributes its excess columns as spaces.
     */
    private function stripColumns(string $line, int $columns, int $pad, int $startColumn): string
    {
        $base = $startColumn - $pad;
        $goal = $base + $columns;

        // The pad columns come first; whatever the goal leaves behind survives
        // as real spaces, so a partially consumed tab keeps its width.
        $consumedPad = min($pad, $columns);
        $prefix = str_repeat(' ', $pad - $consumedPad);

        if ($startColumn >= $goal) {
            return $prefix.$line;
        }

        $offset = 0;
        $column = $startColumn;
        $length = \strlen($line);

        while ($offset < $length && $column < $goal) {
            $byte = $line[$offset];

            if (' ' === $byte) {
                ++$column;
                ++$offset;

                continue;
            }

            if ("\t" === $byte) {
                $column += 4 - ($column % 4);
                ++$offset;

                continue;
            }

            break;
        }

        $overshoot = $column > $goal ? $column - $goal : 0;

        return $prefix.str_repeat(' ', $overshoot).substr($line, $offset);
    }

    /**
     * Removes the opening fence's indentation from one fenced code line: at most
     * $spaces literal spaces, never a tab. Pad columns owed by a partially
     * consumed tab count against that budget first and any survivor renders as a
     * real space.
     */
    private function stripFenceIndent(string $line, int $spaces, int $pad): string
    {
        $removedPad = min($pad, $spaces);
        $pad -= $removedPad;
        $spaces -= $removedPad;
        $offset = 0;
        $length = \strlen($line);

        while ($offset < $length && $spaces > 0 && ' ' === $line[$offset]) {
            --$spaces;
            ++$offset;
        }

        return str_repeat(' ', $pad).substr($line, $offset);
    }

    /**
     * Resolves the backslash escapes and character references a fence info
     * string carries (CommonMark 0.31.2 examples 24 and 34) before the
     * language word is split off. Shares Href::resolve so an info string and
     * a link destination decode the same way; the caller escapes the result
     * for the attribute, so decoding must happen here and only here.
     */
    private function decodeInfo(string $info): string
    {
        if (\strlen($info) === strcspn($info, '\\&')) {
            return $info;
        }

        return Href::resolve($info);
    }

    private function lineFromPair(string $pair): string
    {
        $parts = explode(':', $pair, 3);
        $start = (int) $parts[0];
        $end = (int) ($parts[1] ?? 0);

        return $this->buffer->substring($start, $end);
    }
}
