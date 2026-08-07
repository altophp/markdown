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

use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;

/**
 * HTML blocks, all seven CommonMark start/end condition kinds (SPEC section
 * "HTML blocks", CommonMark 0.31.2).
 *
 * The block's kind (1..7) is recorded in the tape flags field when the block
 * opens and read back on every later line. Types 1-5 close on the first line
 * that contains their end
 * marker, that line included. Types 6-7 close at the first blank line, which is
 * not part of the block; type 7 additionally cannot interrupt a paragraph.
 *
 * The block records its whole source range: startOffset is the cursor at start
 * (leading indentation of up to three columns included), endOffset is the byte
 * after the last member line including its EOL. The renderer emits that range
 * verbatim. Continuation consumes every member line before the core loop can
 * offer it to other Markdown block constructs.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HtmlBlockParser implements OpaqueLeafBlock
{
    private const string ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * The tag names that open a type-6 block, lowercased, as a lookup set.
     *
     * @var array<string, true>
     */
    private const array TYPE6_TAGS = [
        'address' => true, 'article' => true, 'aside' => true, 'base' => true,
        'basefont' => true, 'blockquote' => true, 'body' => true, 'caption' => true,
        'center' => true, 'col' => true, 'colgroup' => true, 'dd' => true,
        'details' => true, 'dialog' => true, 'dir' => true, 'div' => true,
        'dl' => true, 'dt' => true, 'fieldset' => true, 'figcaption' => true,
        'figure' => true, 'footer' => true, 'form' => true, 'frame' => true,
        'frameset' => true, 'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true,
        'h5' => true, 'h6' => true, 'head' => true, 'header' => true, 'hr' => true,
        'html' => true, 'iframe' => true, 'legend' => true, 'li' => true,
        'link' => true, 'main' => true, 'menu' => true, 'menuitem' => true,
        'nav' => true, 'noframes' => true, 'ol' => true, 'optgroup' => true,
        'option' => true, 'p' => true, 'param' => true, 'search' => true,
        'section' => true, 'summary' => true, 'table' => true, 'tbody' => true,
        'td' => true, 'tfoot' => true, 'th' => true, 'thead' => true,
        'title' => true, 'tr' => true, 'track' => true, 'ul' => true,
    ];

    /**
     * @var list<string>
     */
    private const array TYPE1_NAMES = ['pre', 'script', 'style', 'textarea'];

    /**
     * @var list<string>
     */
    private const array TYPE1_END = ['</pre>', '</script>', '</style>', '</textarea>'];

    /**
     * Last content end for root blocks. Their source is contiguous, so one
     * range replaces the per-line range list required inside containers.
     *
     * @var array<int, int>
     */
    private array $rootContentEnd = [];

    public function kind(): int
    {
        return BlockKind::HTML_BLOCK;
    }

    public function triggerBytes(): string
    {
        return '<';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        $offset = $state->offset;
        $end = $state->lineContentEnd;
        $firstNonSpace = $state->firstNonSpaceFrom();

        if ($firstNonSpace >= $end) {
            return null;
        }

        if ($state->cursorIndentFrom($firstNonSpace) >= 4) {
            return null;
        }

        $type = $this->matchStartType($state->buffer, $firstNonSpace, $end);

        if (null === $type) {
            return null;
        }

        if (7 === $type && $paragraphOpen) {
            return null;
        }

        return new BlockStart(BlockKind::HTML_BLOCK, $offset, flags: $type);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $tape = $state->tape;
        $buffer = $state->buffer;
        $line = $state->line;
        $end = $state->lineContentEnd;
        $lineEnd = $state->scanner()->lineEnd($line);

        $type = $tape->flags($ordinal);

        if ($type >= 6) {
            if ($state->firstNonSpaceFrom() >= $end) {
                return ContinueResult::NotMatched;
            }

            $tape->setEndOffset($ordinal, $lineEnd);
            $this->recordLine($state, $ordinal, $end);
            $state->advanceTo($end);

            return ContinueResult::Matched;
        }

        $tape->setEndOffset($ordinal, $lineEnd);
        $this->recordLine($state, $ordinal, $end);
        $closed = $this->hasEndMarker($buffer, $type, $state->offset, $end);
        $state->advanceTo($end);

        return $closed ? ContinueResult::Closed : ContinueResult::Matched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        if (isset($this->rootContentEnd[$ordinal])) {
            $state->tape->setPayload(
                $ordinal,
                $state->tape->startOffset($ordinal).':'.$this->rootContentEnd[$ordinal],
            );
            unset($this->rootContentEnd[$ordinal]);
        }
    }

    private function recordLine(ParserState $state, int $ordinal, int $end): void
    {
        $tape = $state->tape;
        $parent = $tape->parentOrdinal($ordinal);

        if (BlockKind::DOCUMENT === $tape->kindId($parent)) {
            $state->takePendingPad();
            $this->rootContentEnd[$ordinal] = $end;

            return;
        }

        $this->appendPair($tape, $ordinal, $state->offset, $end, $state->takePendingPad());
    }

    /**
     * Records the line's content range (container markers excluded) so the
     * renderer can reproduce the block without marker bleed inside quotes
     * and list items.
     */
    private function appendPair(\Alto\Markdown\Parser\ParseTape $tape, int $ordinal, int $start, int $end, int $pad = 0): void
    {
        $pair = $start.':'.$end.($pad > 0 ? ':'.$pad : '');
        $tape->appendPayloadPart($ordinal, $pair, ';');
    }

    /**
     * The start-condition kind (1..7) for a line whose first non-space byte is
     * at $pos, or null when no condition matches. Conditions are tested in
     * spec-precedence order. Scans the raw byte string directly: per-byte
     * accessor calls dominated block phase 2 on markup-heavy content (PL.2b).
     */
    private function matchStartType(SourceBuffer $buffer, int $pos, int $end): ?int
    {
        $bytes = $buffer->bytes;

        if ($pos >= $buffer->length || '<' !== $bytes[$pos]) {
            return null;
        }

        if ($this->isType1($bytes, $pos, $end)) {
            return 1;
        }

        if (0 === substr_compare($bytes, '<!--', $pos, 4)) {
            return 2;
        }

        if (0 === substr_compare($bytes, '<?', $pos, 2)) {
            return 3;
        }

        if (0 === substr_compare($bytes, '<!', $pos, 2) && $pos + 2 < $buffer->length && ctype_alpha($bytes[$pos + 2])) {
            return 4;
        }

        if (0 === substr_compare($bytes, '<![CDATA[', $pos, 9)) {
            return 5;
        }

        if ($this->isType6($bytes, $pos, $end, $buffer->length)) {
            return 6;
        }

        if ($this->isType7($bytes, $pos, $end, $buffer->length)) {
            return 7;
        }

        return null;
    }

    private function isType1(string $bytes, int $pos, int $end): bool
    {
        foreach (self::TYPE1_NAMES as $name) {
            if (0 !== substr_compare($bytes, $name, $pos + 1, \strlen($name), true)) {
                continue;
            }

            $after = $pos + 1 + \strlen($name);

            if ($after >= $end) {
                return true;
            }

            $byte = $bytes[$after];

            if (' ' === $byte || "\t" === $byte || '>' === $byte) {
                return true;
            }
        }

        return false;
    }

    private function isType6(string $bytes, int $pos, int $end, int $length): bool
    {
        $p = $pos + 1;

        if ($p < $length && '/' === $bytes[$p]) {
            ++$p;
        }

        $nameStart = $p;
        $p += strspn($bytes, self::ALNUM, $p, $end - $p);

        if ($p === $nameStart) {
            return false;
        }

        if (!isset(self::TYPE6_TAGS[strtolower(substr($bytes, $nameStart, $p - $nameStart))])) {
            return false;
        }

        if ($p >= $end) {
            return true;
        }

        $byte = $bytes[$p];

        if (' ' === $byte || "\t" === $byte || '>' === $byte) {
            return true;
        }

        return '/' === $byte && $p + 1 < $length && '>' === $bytes[$p + 1];
    }

    private function isType7(string $bytes, int $pos, int $end, int $length): bool
    {
        $after = $this->scanCompleteTag($bytes, $pos, $end, $length);

        if (null === $after) {
            return false;
        }

        return strspn($bytes, " \t", $after, $end - $after) === $end - $after;
    }

    /**
     * Offset just past a complete open or closing tag beginning at $pos, or
     * null when the bytes are not one. Tag names pre/script/style/textarea are
     * rejected, matching the type-7 exclusion.
     */
    private function scanCompleteTag(string $bytes, int $pos, int $end, int $length): ?int
    {
        $p = $pos + 1;
        $closing = false;

        if ($p < $length && '/' === $bytes[$p]) {
            $closing = true;
            ++$p;
        }

        if ($p >= $length || !ctype_alpha($bytes[$p])) {
            return null;
        }

        $nameStart = $p;
        ++$p;
        $p += strspn($bytes, self::ALNUM.'-', $p, $end - $p);

        if (\in_array(strtolower(substr($bytes, $nameStart, $p - $nameStart)), self::TYPE1_NAMES, true)) {
            return null;
        }

        if ($closing) {
            $p += strspn($bytes, " \t", $p, $end - $p);

            return $p < $length && '>' === $bytes[$p] ? $p + 1 : null;
        }

        while (true) {
            $skipped = strspn($bytes, " \t", $p, $end - $p);

            if (0 === $skipped) {
                break;
            }

            $p += $skipped;
            $attrEnd = $this->scanAttribute($bytes, $p, $end, $length);

            if (null === $attrEnd) {
                break;
            }

            $p = $attrEnd;
        }

        $p += strspn($bytes, " \t", $p, $end - $p);

        if ($p < $length && '/' === $bytes[$p]) {
            ++$p;
        }

        return $p < $length && '>' === $bytes[$p] ? $p + 1 : null;
    }

    /**
     * Offset past an attribute (name plus optional value specification)
     * beginning at $pos, or null when no attribute name is present.
     */
    private function scanAttribute(string $bytes, int $pos, int $end, int $length): ?int
    {
        if ($pos >= $length) {
            return null;
        }

        $byte = $bytes[$pos];

        if ('_' !== $byte && ':' !== $byte && !ctype_alpha($byte)) {
            return null;
        }

        $p = $pos + 1;
        $p += strspn($bytes, self::ALNUM.'_.:-', $p, $end - $p);

        return $this->scanValueSpec($bytes, $p, $end, $length) ?? $p;
    }

    /**
     * Offset past an attribute value specification (=, then a quoted or
     * unquoted value) beginning at $pos, or null when none is present or the
     * value is malformed.
     */
    private function scanValueSpec(string $bytes, int $pos, int $end, int $length): ?int
    {
        $p = $pos + strspn($bytes, " \t", $pos, $end - $pos);

        if ($p >= $length || '=' !== $bytes[$p]) {
            return null;
        }

        ++$p;
        $p += strspn($bytes, " \t", $p, $end - $p);

        if ($p >= $length) {
            return null;
        }

        $byte = $bytes[$p];

        if ('"' === $byte || "'" === $byte) {
            ++$p;
            $p += strcspn($bytes, $byte, $p, $end - $p);

            return $p < $end ? $p + 1 : null;
        }

        $valueStart = $p;
        $p += strcspn($bytes, " \t\"'=<>`", $p, $end - $p);

        return $p > $valueStart ? $p : null;
    }

    /**
     * Whether the line from $from to $end contains the type's end marker.
     */
    private function hasEndMarker(SourceBuffer $buffer, int $type, int $from, int $end): bool
    {
        $haystack = $buffer->substring($from, $end);

        return match ($type) {
            1 => $this->containsCI($haystack, self::TYPE1_END),
            2 => str_contains($haystack, '-->'),
            3 => str_contains($haystack, '?>'),
            4 => str_contains($haystack, '>'),
            5 => str_contains($haystack, ']]>'),
            default => false,
        };
    }

    /**
     * @param list<string> $needles
     */
    private function containsCI(string $haystack, array $needles): bool
    {
        $lower = strtolower($haystack);

        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
