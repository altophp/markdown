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

use Alto\Markdown\Parser\BlockCountBudget;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;

/**
 * Link reference definitions. Unlike every other construct these do not start
 * on a line of their own: CommonMark parses them out of the front of a
 * paragraph. So tryStart/tryContinue stay inert and the real work runs from
 * extractInto(), which the block loop calls when a paragraph closes.
 *
 * extractInto() peels complete definitions off the front of the paragraph's
 * raw bytes, records each in the ReferenceMap (first definition of a label
 * wins), emits one LINK_REFERENCE_DEFINITION node per definition, and either
 * keeps a trailing paragraph for the leftover text or drops the paragraph when
 * it was nothing but definitions.
 *
 * A definition is a link label, up to three spaces of indentation allowed,
 * then a colon, optional spaces or tabs including up to one line ending, a
 * link destination, then an optional title separated from the destination by
 * spaces or tabs (which may include one line ending). No other character may
 * follow on the line. Destinations and titles are stored raw (delimiters
 * removed, escapes not yet resolved); inline processing in step 3 resolves
 * escapes and encodes them.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class LinkReferenceDefinitionParser implements BlockConstruct
{
    private const int MAX_LABEL_BYTES = 999;

    /**
     * Bytes that interrupt a scan (W4.3). Each scanner jumps to the next one
     * with strcspn rather than stepping byte by byte in PHP, then decides on
     * the byte it landed on. The classes list exactly the bytes the per-byte
     * loops used to test for, so a jump lands where a step would have stopped.
     */
    private const string LABEL_STOP = '\\[]';

    private const string ANGLE_DESTINATION_STOP = "\\<>\r\n";

    /**
     * A bare destination ends at any byte at or below 0x20, at DEL, or at a
     * parenthesis; a backslash starts an escape.
     */
    private const string DESTINATION_STOP = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20\x7f\\()";

    public function kind(): int
    {
        return BlockKind::LINK_REFERENCE_DEFINITION;
    }

    public function triggerBytes(): string
    {
        return '';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        return null;
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        return ContinueResult::NotMatched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
    }

    /**
     * Extract leading reference definitions from a closing paragraph.
     *
     * Returns the parent's new last-child ordinal so the loop can keep its
     * sibling bookkeeping. When the paragraph holds no definition it is left
     * untouched and its own ordinal is returned (the common fast path).
     */
    public function extractInto(
        ParserState $state,
        ReferenceMap $map,
        int $paragraphOrdinal,
        int $parentOrdinal,
        int $prevSibling,
        ?BlockCountBudget $blockCountBudget = null,
    ): int {
        $tape = $state->tape;
        $start = $tape->startOffset($paragraphOrdinal);
        $end = $tape->endOffset($paragraphOrdinal);

        if ($end <= $start) {
            return $paragraphOrdinal;
        }

        if (Instrumentation::$timing) {
            ++Instrumentation::$blockRefdefScans;
        }

        // Fast path: a definition can only begin with up to three spaces of
        // indentation and an opening bracket. Probing those bytes avoids
        // entering the scanner at all for the common no-definition paragraph
        // (PL.2b); parseDefinition applies the same rule (skipUpToThreeSpaces,
        // then '[') from the same offset.
        $bytes = $state->buffer->bytes;
        $pos = $start;
        $probeLimit = min($end, $start + 3);

        while ($pos < $probeLimit && ' ' === $bytes[$pos]) {
            ++$pos;
        }

        if ($pos >= $end || '[' !== $bytes[$pos]) {
            return $paragraphOrdinal;
        }

        // The scanner reads the source buffer directly between the paragraph's
        // absolute bounds (W4.3). Copying the span first cost one allocation
        // and a full memcpy per candidate paragraph even though a paragraph
        // that only looks like a definition is rejected within a few bytes,
        // so scanning in place bounds the work by what is actually examined
        // rather than by the paragraph's length. Every helper takes $end as
        // its exclusive limit, so nothing reads past the paragraph.
        $pos = $start;

        /** @var list<array{start: int, end: int, label: string}> $definitions */
        $definitions = [];

        while ($pos < $end) {
            $parsed = $this->parseDefinition($bytes, $end, $pos);

            if (null === $parsed) {
                break;
            }

            if (Instrumentation::$timing) {
                Instrumentation::$blockRefdefScanBytes += $parsed['end'] - $pos;
            }

            $label = $map->define($parsed['label'], $parsed['destination'], $parsed['title'], $pos);
            $definitions[] = ['start' => $pos, 'end' => $parsed['end'], 'label' => $label];
            $pos = $parsed['end'];
        }

        if ([] === $definitions) {
            return $paragraphOrdinal;
        }

        $replacementCount = \count($definitions) + ($pos < $end ? 1 : 0);
        $blockCountBudget?->replaceOneWith($replacementCount, $start);

        $previous = $prevSibling;

        foreach ($definitions as $definition) {
            $node = $tape->allocate(BlockKind::LINK_REFERENCE_DEFINITION, $parentOrdinal, $definition['start'], 0);
            $tape->setEndOffset($node, $definition['end']);
            $tape->setPayload($node, $definition['label']);
            $this->link($tape, $parentOrdinal, $previous, $node);
            $previous = $node;
        }

        if ($pos < $end) {
            $leftover = $tape->allocate(BlockKind::PARAGRAPH, $parentOrdinal, $pos, 0);
            $tape->setEndOffset($leftover, $end);
            $tape->setPayload($leftover, $this->survivingPairs($tape->payload($paragraphOrdinal), $pos, $end));
            $this->link($tape, $parentOrdinal, $previous, $leftover);

            return $leftover;
        }

        return $previous;
    }

    /**
     * The closing paragraph's per-line content pairs that survive extraction:
     * those beginning at or after $boundary, the byte just past the last
     * consumed definition. A definition always runs to a line ending, so
     * $boundary is a line start and no pair straddles it. Preserving the
     * pairs keeps each leftover segment a single line, which the inline
     * scanner requires; the whole-span fallback only applies to a paragraph
     * with no recorded pairs, which is always single-line here.
     */
    private function survivingPairs(?string $payload, int $boundary, int $end): string
    {
        $kept = [];

        foreach (null === $payload ? [] : explode(';', $payload) as $pair) {
            if ((int) $pair >= $boundary) {
                $kept[] = $pair;
            }
        }

        return [] === $kept ? $boundary.':'.$end : implode(';', $kept);
    }

    private function link(ParseTape $tape, int $parent, int $previous, int $node): void
    {
        if (ParseTape::NONE === $previous) {
            $tape->linkFirstChild($parent, $node);

            return;
        }

        $tape->linkNextSibling($previous, $node);
    }

    /**
     * Parse one definition starting at absolute offset $pos, reading $bytes
     * (the whole source buffer) up to the exclusive limit $limit (the closing
     * paragraph's end). Returns its parts and the offset just past it (after
     * the terminating line ending), or null when no complete definition starts
     * here.
     *
     * Each rejection credits $blockRefdefScanBytes with what the attempt
     * touched, so the counter measures scanner reads rather than paragraph
     * size. A malformed label is credited the whole remaining span, an upper
     * bound.
     *
     * @return array{label: string, destination: string, title: string|null, end: int}|null
     */
    private function parseDefinition(string $bytes, int $limit, int $pos): ?array
    {
        $cursor = $this->skipUpToThreeSpaces($bytes, $limit, $pos);

        if ($cursor >= $limit || '[' !== $bytes[$cursor]) {
            if (Instrumentation::$timing) {
                Instrumentation::$blockRefdefScanBytes += min($cursor + 1, $limit) - $pos;
            }

            return null;
        }

        $label = $this->scanLabel($bytes, $limit, $cursor);

        if (null === $label) {
            if (Instrumentation::$timing) {
                Instrumentation::$blockRefdefScanBytes += $limit - $pos;
            }

            return null;
        }

        if ($label['end'] >= $limit || ':' !== $bytes[$label['end']]) {
            if (Instrumentation::$timing) {
                Instrumentation::$blockRefdefScanBytes += min($label['end'] + 1, $limit) - $pos;
            }

            return null;
        }

        $cursor = $this->skipInlineWhitespace($bytes, $limit, $label['end'] + 1);
        $destination = $this->scanDestination($bytes, $limit, $cursor);

        if (null === $destination) {
            if (Instrumentation::$timing) {
                Instrumentation::$blockRefdefScanBytes += $limit - $pos;
            }

            return null;
        }

        $tail = $this->scanTitleAndEnd($bytes, $limit, $destination['end']);

        if (null === $tail) {
            if (Instrumentation::$timing) {
                Instrumentation::$blockRefdefScanBytes += $limit - $pos;
            }

            return null;
        }

        return ['label' => $label['label'], 'destination' => $destination['value'], 'title' => $tail['title'], 'end' => $tail['end']];
    }

    private function skipUpToThreeSpaces(string $bytes, int $limit, int $pos): int
    {
        $stop = min($limit, $pos + 3);

        while ($pos < $stop && ' ' === $bytes[$pos]) {
            ++$pos;
        }

        return $pos;
    }

    /**
     * Scan a bracketed link label at $pos. Returns the raw inner text and the
     * offset past the closing bracket, or null when the label is malformed,
     * empty, over-long, or unterminated.
     *
     * @return array{label: string, end: int}|null
     */
    private function scanLabel(string $bytes, int $limit, int $pos): ?array
    {
        $index = $pos + 1;

        while ($index < $limit) {
            $index += strcspn($bytes, self::LABEL_STOP, $index, $limit - $index);

            if ($index >= $limit) {
                break;
            }

            $char = $bytes[$index];

            if ('\\' === $char) {
                // A backslash with nothing after it inside the paragraph is an
                // ordinary byte, and there is no closing bracket left to find.
                if ($index + 1 >= $limit) {
                    break;
                }

                $index += 2;

                continue;
            }

            if ('[' === $char) {
                return null;
            }

            $inner = substr($bytes, $pos + 1, $index - $pos - 1);

            if ('' === trim($inner, " \t\r\n\f\x0b") || \strlen($inner) > self::MAX_LABEL_BYTES) {
                return null;
            }

            return ['label' => $inner, 'end' => $index + 1];
        }

        return null;
    }

    /**
     * Skip spaces or tabs, then up to one line ending, then spaces or tabs.
     */
    private function skipInlineWhitespace(string $bytes, int $limit, int $pos): int
    {
        $pos = $this->skipSpacesAndTabs($bytes, $limit, $pos);

        if ($pos < $limit && ("\n" === $bytes[$pos] || "\r" === $bytes[$pos])) {
            $pos = $this->consumeLineEnding($bytes, $limit, $pos);
            $pos = $this->skipSpacesAndTabs($bytes, $limit, $pos);
        }

        return $pos;
    }

    /**
     * Scan a link destination at $pos: either an angle-bracketed run or a bare
     * run with balanced parentheses. Returns the raw inner text (angle brackets
     * stripped) and the offset past it, or null.
     *
     * @return array{value: string, end: int}|null
     */
    private function scanDestination(string $bytes, int $limit, int $pos): ?array
    {
        if ($pos >= $limit) {
            return null;
        }

        if ('<' === $bytes[$pos]) {
            $index = $pos + 1;

            while ($index < $limit) {
                $index += strcspn($bytes, self::ANGLE_DESTINATION_STOP, $index, $limit - $index);

                if ($index >= $limit) {
                    return null;
                }

                $char = $bytes[$index];

                if ('\\' === $char) {
                    // A trailing backslash leaves no closing angle bracket.
                    if ($index + 1 >= $limit) {
                        return null;
                    }

                    $index += 2;

                    continue;
                }

                if ('>' === $char) {
                    return ['value' => substr($bytes, $pos + 1, $index - $pos - 1), 'end' => $index + 1];
                }

                // '<' or a line ending: the run is not a destination.
                return null;
            }
        }

        $index = $pos;
        $depth = 0;

        while ($index < $limit) {
            $index += strcspn($bytes, self::DESTINATION_STOP, $index, $limit - $index);

            if ($index >= $limit) {
                break;
            }

            $char = $bytes[$index];

            if ('\\' === $char) {
                // A trailing backslash is an ordinary destination byte.
                if ($index + 1 >= $limit) {
                    $index = $limit;

                    break;
                }

                $index += 2;

                continue;
            }

            if ('(' === $char) {
                ++$depth;
                ++$index;

                continue;
            }

            if (')' === $char) {
                if (0 === $depth) {
                    break;
                }

                --$depth;
                ++$index;

                continue;
            }

            // A byte at or below 0x20, or DEL: the destination ends here.
            break;
        }

        if (0 !== $depth || $index === $pos) {
            return null;
        }

        return ['value' => substr($bytes, $pos, $index - $pos), 'end' => $index];
    }

    /**
     * After the destination, resolve the optional title and the definition's
     * end. Returns the title (or null when absent) and the offset past the
     * definition, or null when what follows the destination is not a valid
     * definition ending.
     *
     * @return array{title: string|null, end: int}|null
     */
    private function scanTitleAndEnd(string $bytes, int $limit, int $afterDestination): ?array
    {
        $whitespaceEnd = $this->skipSpacesAndTabs($bytes, $limit, $afterDestination);
        $crossedLineEnding = false;

        if ($whitespaceEnd < $limit && ("\n" === $bytes[$whitespaceEnd] || "\r" === $bytes[$whitespaceEnd])) {
            $whitespaceEnd = $this->consumeLineEnding($bytes, $limit, $whitespaceEnd);
            $whitespaceEnd = $this->skipSpacesAndTabs($bytes, $limit, $whitespaceEnd);
            $crossedLineEnding = true;
        }

        $separated = $whitespaceEnd > $afterDestination;
        $opener = $whitespaceEnd < $limit ? $bytes[$whitespaceEnd] : '';

        if ($separated && ('"' === $opener || "'" === $opener || '(' === $opener)) {
            $title = $this->scanTitle($bytes, $limit, $whitespaceEnd);

            if (null !== $title) {
                $lineEnd = $this->requireLineEnd($bytes, $limit, $title['end']);

                if (null !== $lineEnd) {
                    return ['title' => $title['title'], 'end' => $lineEnd];
                }
            }

            if ($crossedLineEnding) {
                return $this->endWithoutTitle($bytes, $limit, $afterDestination);
            }

            return null;
        }

        return $this->endWithoutTitle($bytes, $limit, $afterDestination);
    }

    /**
     * A definition with no title: the destination's line must contain nothing
     * but the destination and trailing spaces or tabs.
     *
     * @return array{title: string|null, end: int}|null
     */
    private function endWithoutTitle(string $bytes, int $limit, int $afterDestination): ?array
    {
        $lineEnd = $this->requireLineEnd($bytes, $limit, $afterDestination);

        if (null === $lineEnd) {
            return null;
        }

        return ['title' => null, 'end' => $lineEnd];
    }

    /**
     * Require only spaces or tabs then a line ending or the paragraph's end.
     * Returns the offset past the line ending (or $limit) or null when other
     * characters intervene.
     */
    private function requireLineEnd(string $bytes, int $limit, int $pos): ?int
    {
        $pos = $this->skipSpacesAndTabs($bytes, $limit, $pos);

        if ($pos >= $limit) {
            return $limit;
        }

        if ("\n" === $bytes[$pos] || "\r" === $bytes[$pos]) {
            return $this->consumeLineEnding($bytes, $limit, $pos);
        }

        return null;
    }

    /**
     * Scan a title opened at $pos by one of " ' ( with the matching closer.
     * Returns the raw inner text and the offset past the closer, or null when
     * unterminated. Paragraph content never holds a blank line, so an
     * unterminated title simply runs to $limit, the paragraph's end.
     *
     * @return array{title: string, end: int}|null
     */
    private function scanTitle(string $bytes, int $limit, int $pos): ?array
    {
        $open = $bytes[$pos];
        $close = '(' === $open ? ')' : $open;

        // A parenthesised title is also interrupted by a nested opener.
        $stop = match ($open) {
            '(' => '\\()',
            '"' => '\\"',
            default => "\\'",
        };

        $index = $pos + 1;

        while ($index < $limit) {
            $index += strcspn($bytes, $stop, $index, $limit - $index);

            if ($index >= $limit) {
                return null;
            }

            $char = $bytes[$index];

            if ('\\' === $char) {
                // A trailing backslash leaves the title unterminated.
                if ($index + 1 >= $limit) {
                    return null;
                }

                $index += 2;

                continue;
            }

            if ('(' === $open && '(' === $char) {
                return null;
            }

            return ['title' => substr($bytes, $pos + 1, $index - $pos - 1), 'end' => $index + 1];
        }

        return null;
    }

    private function skipSpacesAndTabs(string $bytes, int $limit, int $pos): int
    {
        return $pos >= $limit ? $pos : $pos + strspn($bytes, " \t", $pos, $limit - $pos);
    }

    private function consumeLineEnding(string $bytes, int $limit, int $pos): int
    {
        if ("\r" === $bytes[$pos] && $pos + 1 < $limit && "\n" === $bytes[$pos + 1]) {
            return $pos + 2;
        }

        return $pos + 1;
    }
}
