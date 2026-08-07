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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Parser\Instrumentation;

/**
 * Raw inline HTML (CommonMark 0.31.2, section "Raw HTML"): an open tag, a
 * closing tag, an HTML comment, a processing instruction, a declaration, or a
 * CDATA section, each recognised by the spec's own grammar rather than by any
 * list of known tag names. The grammar is the inline one; it overlaps the
 * block HTML grammar but is not identical.
 *
 * A matched tag is emitted verbatim: the HTML_INLINE payload is the content
 * slice (never the raw source slice), so container markers stripped during
 * inline content assembly cannot leak back into a tag that spans lines (a
 * comment, for example). Whitespace inside a tag may include a single line
 * ending because the content joins a block's lines with "\n".
 *
 * Autolinks are consulted before this construct (registry order), so `<` only
 * reaches here once it is not a URI or email autolink.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class RawHtmlParser implements InlineConstruct
{
    private const string ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * Tag-name continuation bytes: ASCII alphanumerics and "-".
     */
    private const string TAG_NAME_CONT = self::ALNUM.'-';

    /**
     * Attribute-name continuation bytes: alphanumerics, "_", ".", ":", "-".
     */
    private const string ATTR_NAME_CONT = self::ALNUM.'_.:-';

    /**
     * Bytes that end an unquoted attribute value: every byte <= 0x20 (spaces,
     * tabs, line endings, controls) plus " ' = < > and backtick.
     */
    private const string UNQUOTED_STOP = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20\"'=<>`";

    public function triggerBytes(): string
    {
        return '<';
    }

    public function tryParse(InlineScanState $state): bool
    {
        $text = $state->content()->text;
        $start = $state->offset();

        if (Instrumentation::$timing) {
            ++Instrumentation::$rawHtmlCandidates;
        }

        $end = $this->matchTag($text, $start, \strlen($text));

        if (null === $end) {
            return false;
        }

        if (Instrumentation::$timing) {
            ++Instrumentation::$rawHtmlMatches;
            Instrumentation::$rawHtmlScanBytes += $end - $start;
        }

        $state->emit(InlineKind::HTML_INLINE, $end, 0, substr($text, $start, $end - $start));

        return true;
    }

    /**
     * Content offset just past a complete HTML tag starting at $start (the
     * `<`), or null when the bytes there are not one.
     */
    private function matchTag(string $s, int $start, int $len): ?int
    {
        $next = $start + 1;

        if ($next >= $len) {
            return null;
        }

        $byte = $s[$next];

        if ('/' === $byte) {
            return $this->closingTag($s, $next + 1, $len);
        }

        if ('?' === $byte) {
            return $this->terminatedBy($s, $next + 1, $len, '?>');
        }

        if ('!' === $byte) {
            return $this->markup($s, $start, $len);
        }

        if ($this->isLetter(\ord($byte))) {
            return $this->openTag($s, $next, $len);
        }

        return null;
    }

    private function openTag(string $s, int $p, int $len): ?int
    {
        // Caller has checked the first byte is an ASCII letter.
        $p += 1 + strspn($s, self::TAG_NAME_CONT, $p + 1);

        while (true) {
            $before = $p;
            $p = $this->skipSpace($s, $p, $len);

            if ($p === $before) {
                break;
            }

            $attribute = $this->attribute($s, $p, $len);

            if (null === $attribute) {
                break;
            }

            $p = $attribute;
        }

        $p = $this->skipSpace($s, $p, $len);

        if ($p < $len && '/' === $s[$p]) {
            ++$p;
        }

        return $p < $len && '>' === $s[$p] ? $p + 1 : null;
    }

    private function closingTag(string $s, int $p, int $len): ?int
    {
        if ($p >= $len || !$this->isLetter(\ord($s[$p]))) {
            return null;
        }

        $p += 1 + strspn($s, self::TAG_NAME_CONT, $p + 1);
        $p = $this->skipSpace($s, $p, $len);

        return $p < $len && '>' === $s[$p] ? $p + 1 : null;
    }

    /**
     * A comment, CDATA section, or declaration, all beginning `<!`.
     */
    private function markup(string $s, int $start, int $len): ?int
    {
        if ($this->startsWith($s, $start, '<!-->', $len)) {
            return $start + 5;
        }

        if ($this->startsWith($s, $start, '<!--->', $len)) {
            return $start + 6;
        }

        if ($this->startsWith($s, $start, '<!--', $len)) {
            return $this->terminatedBy($s, $start + 4, $len, '-->');
        }

        if ($this->startsWith($s, $start, '<![CDATA[', $len)) {
            return $this->terminatedBy($s, $start + 9, $len, ']]>');
        }

        // Declaration: '<!', an ASCII letter, then any bytes up to '>'.
        $p = $start + 2;

        if ($p < $len && $this->isLetter(\ord($s[$p]))) {
            return $this->terminatedBy($s, $p + 1, $len, '>');
        }

        return null;
    }

    /**
     * Offset past an attribute (a name and an optional value specification)
     * beginning at $p, or null when no attribute name is present.
     */
    private function attribute(string $s, int $p, int $len): ?int
    {
        if ($p >= $len) {
            return null;
        }

        $byte = \ord($s[$p]);

        if (!$this->isLetter($byte) && 0x5F !== $byte && 0x3A !== $byte) {
            return null;
        }

        $p += 1 + strspn($s, self::ATTR_NAME_CONT, $p + 1);

        return $this->valueSpec($s, $p, $len) ?? $p;
    }

    /**
     * Offset past a value specification (optional whitespace, `=`, optional
     * whitespace, then a quoted or unquoted value), or null when none is
     * present or the value is malformed.
     */
    private function valueSpec(string $s, int $p, int $len): ?int
    {
        $q = $this->skipSpace($s, $p, $len);

        if ($q >= $len || '=' !== $s[$q]) {
            return null;
        }

        $q = $this->skipSpace($s, $q + 1, $len);

        if ($q >= $len) {
            return null;
        }

        $quote = $s[$q];

        if ('"' === $quote || "'" === $quote) {
            $close = strpos($s, $quote, $q + 1);

            return false === $close ? null : $close + 1;
        }

        $stop = $q + strcspn($s, self::UNQUOTED_STOP, $q);

        return $stop > $q ? $stop : null;
    }

    /**
     * Offset past $needle after $from, or null when it does not occur.
     */
    private function terminatedBy(string $s, int $from, int $len, string $needle): ?int
    {
        $at = strpos($s, $needle, $from);

        return false === $at ? null : $at + \strlen($needle);
    }

    /**
     * Skips spaces and tabs plus at most one line ending, per the inline
     * attribute-whitespace rule.
     */
    private function skipSpace(string $s, int $p, int $len): int
    {
        // Spaces and tabs, then at most one line ending, then more spaces and
        // tabs: the inline attribute-whitespace rule.
        $p += strspn($s, " \t", $p);

        if ($p < $len && "\n" === $s[$p]) {
            $p += 1 + strspn($s, " \t", $p + 1);
        }

        return $p;
    }

    private function startsWith(string $s, int $p, string $literal, int $len): bool
    {
        $literalLength = \strlen($literal);

        return $p + $literalLength <= $len && 0 === substr_compare($s, $literal, $p, $literalLength);
    }

    private function isLetter(int $byte): bool
    {
        return ($byte >= 0x41 && $byte <= 0x5A) || ($byte >= 0x61 && $byte <= 0x7A);
    }
}
