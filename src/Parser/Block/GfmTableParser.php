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

use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;

/**
 * GFM table leaf block.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GfmTableParser implements BlockConstruct, ParagraphReplacementValidator
{
    public function __construct(private readonly int $kind) {}

    public function kind(): int
    {
        return $this->kind;
    }

    public function triggerBytes(): string
    {
        return '|:-';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        if (!$paragraphOpen) {
            return null;
        }

        $first = $state->firstNonSpaceFrom();

        if ($state->cursorIndentFrom($first) > 3) {
            return null;
        }

        $line = $this->lineText($state);
        $alignments = self::delimiterAlignments($line);

        if (null === $alignments) {
            return null;
        }

        return new BlockStart($this->kind, $first, replacesParagraph: true, payload: implode(',', $alignments));
    }

    public function canReplaceParagraph(ParserState $state, ParseTape $tape, int $paragraph, BlockStart $start): bool
    {
        $payload = $tape->payload($paragraph);

        if (null === $payload || str_contains($payload, ';')) {
            return false;
        }

        [$startOffset, $endOffset] = array_map('intval', explode(':', $payload, 3));
        $header = $state->buffer->substring($startOffset, $endOffset);
        $alignments = [] === explode(',', $start->payload ?? '') ? [] : explode(',', $start->payload ?? '');

        return \count(self::splitRow($header)) === \count($alignments);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $tape = $state->tape;
        $payload = $tape->payload($ordinal) ?? '';
        $first = $state->firstNonSpaceFrom();
        $end = $state->lineContentEnd;

        if (!str_contains($payload, '|')) {
            $alignments = self::delimiterAlignments($this->lineText($state));

            if (null === $alignments) {
                return ContinueResult::NotMatched;
            }

            $tape->setPayload($ordinal, $payload . '|' . implode(',', $alignments) . '|');
            $tape->setEndOffset($ordinal, $end);
            $state->advanceTo($end);

            return ContinueResult::Matched;
        }

        if ($first >= $end || $this->startsOtherBlock($state)) {
            return ContinueResult::NotMatched;
        }

        $row = $first . ':' . $end;
        $tape->setPayload($ordinal, $payload . ('|' === $payload[\strlen($payload) - 1] ? '' : ';') . $row);
        $tape->setEndOffset($ordinal, $end);
        $state->advanceTo($end);

        return ContinueResult::Matched;
    }

    public function close(ParserState $state, int $ordinal): void {}

    /**
     * @return ?list<'left'|'center'|'right'|''>
     */
    public static function delimiterAlignments(string $line): ?array
    {
        $cells = self::splitRow($line);
        $alignments = [];

        foreach ($cells as $cell) {
            $cell = trim($cell);

            if (1 !== preg_match('/^:?-+:?$/', $cell)) {
                return null;
            }

            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            $alignments[] = match (true) {
                $left && $right => 'center',
                $right => 'right',
                $left => 'left',
                default => '',
            };
        }

        return $alignments;
    }

    /**
     * @return list<string>
     */
    public static function splitRow(string $line): array
    {
        $line = trim($line);

        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }

        if (str_ends_with($line, '|') && !str_ends_with($line, '\\|')) {
            $line = substr($line, 0, -1);
        }

        $cells = [''];
        $codeSpanDelimiter = 0;
        $length = \strlen($line);

        for ($i = 0; $i < $length; ++$i) {
            $byte = $line[$i];

            if ('`' === $byte) {
                $run = strspn($line, '`', $i);

                if (0 === $codeSpanDelimiter) {
                    $codeSpanDelimiter = $run;
                } elseif ($run === $codeSpanDelimiter) {
                    $codeSpanDelimiter = 0;
                }

                $cells[\count($cells) - 1] .= substr($line, $i, $run);
                $i += $run - 1;

                continue;
            }

            if ('|' === $byte && 0 === $codeSpanDelimiter && (0 === $i || '\\' !== $line[$i - 1])) {
                $cells[] = '';

                continue;
            }

            $cells[\count($cells) - 1] .= $byte;
        }

        return array_map(static fn(string $cell): string => trim(str_replace('\\|', '|', $cell)), $cells);
    }

    private function lineText(ParserState $state): string
    {
        return $state->buffer->substring($state->firstNonSpaceFrom(), $state->lineContentEnd);
    }

    private function startsOtherBlock(ParserState $state): bool
    {
        $byte = $state->buffer->byteAt($state->firstNonSpaceFrom());

        if (\in_array($byte, [0x23, 0x3E, 0x60, 0x7E], true)) {
            return true;
        }

        if (null !== ListItemParser::matchMarker($state)) {
            return true;
        }

        return null !== (new ThematicBreakParser())->tryStart($state, 0, false);
    }
}
