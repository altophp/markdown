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

/**
 * Inline autolinks (CommonMark 0.31.2, section "Autolinks"): a URI autolink
 * `<scheme:rest>` or an email autolink `<addr@host>`, both delimited by `<`
 * and `>`. The content between the brackets never contains whitespace, an
 * ASCII control byte, `<`, or `>`, so an autolink can never span a line
 * break (the joint "\n" is a control byte that ends the scan).
 *
 * A URI autolink renders as a link whose href is the URL with characters
 * outside the href-safe set percent-encoded (backslashes and brackets, for
 * example), and whose label is the raw URL. An email autolink renders with a
 * `mailto:` href. The AUTOLINK node's source slice is exactly the label, so
 * the angle brackets must stay out of it: the emit API fixes a node's start
 * at the cursor, so the brackets are consumed as empty text (which renders to
 * nothing) around the label node.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class AutolinkParser implements InlineConstruct
{
    /**
     * ASCII printable bytes percent-encoded in an autolink href, matching
     * cmark's HREF_SAFE table for spec 0.31.2 (`&` and `'` stay literal and
     * are HTML-escaped by the renderer instead).
     */
    /**
     * The email address grammar: the non-normative HTML5 regex the spec
     * cites, anchored to the whole content.
     */
    private const string EMAIL = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/D';

    public function triggerBytes(): string
    {
        return '<';
    }

    public function tryParse(InlineScanState $state): bool
    {
        $text = $state->content()->text;
        $length = \strlen($text);
        $inner = $state->offset() + 1;

        $close = $inner;

        while ($close < $length) {
            $byte = \ord($text[$close]);

            if (0x3E === $byte) {
                break;
            }

            // Space, any ASCII control byte, or a nested `<` disqualifies.
            if ($byte <= 0x20 || 0x7F === $byte || 0x3C === $byte) {
                return false;
            }

            ++$close;
        }

        if ($close >= $length) {
            return false;
        }

        $label = substr($text, $inner, $close - $inner);
        $href = $this->uriHref($label) ?? $this->emailHref($label);

        if (null === $href) {
            return false;
        }

        $state->emit(InlineKind::TEXT, $inner, 0, '');
        $state->emit(InlineKind::AUTOLINK, $close, 0, $href);
        $state->emit(InlineKind::TEXT, $close + 1, 0, '');

        return true;
    }

    /**
     * The href for a URI autolink, or null when the content is not an
     * absolute URI: a scheme of 2 to 32 characters (an ASCII letter then
     * letters, digits, `+`, `.`, or `-`) followed by a colon.
     */
    private function uriHref(string $content): ?string
    {
        $length = \strlen($content);

        if ($length < 3 || !$this->isSchemeStart(\ord($content[0]))) {
            return null;
        }

        $scheme = 1;

        while ($scheme < $length && $this->isSchemeByte(\ord($content[$scheme]))) {
            ++$scheme;
        }

        if ($scheme < 2 || $scheme > 32 || $scheme >= $length || ':' !== $content[$scheme]) {
            return null;
        }

        return Href::encode($content);
    }

    private function emailHref(string $content): ?string
    {
        if (1 !== preg_match(self::EMAIL, $content)) {
            return null;
        }

        return Href::encode('mailto:' . $content);
    }

    private function isSchemeStart(int $byte): bool
    {
        return ($byte >= 0x41 && $byte <= 0x5A) || ($byte >= 0x61 && $byte <= 0x7A);
    }

    private function isSchemeByte(int $byte): bool
    {
        return $this->isSchemeStart($byte)
            || ($byte >= 0x30 && $byte <= 0x39)
            || 0x2B === $byte
            || 0x2E === $byte
            || 0x2D === $byte;
    }
}
