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

namespace Alto\Markdown\Render;

use Alto\Markdown\Parser\Inline\InlineSourceView;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Profile\CompiledProfile;

/**
 * Decides whether a run of inline source is plain text (would parse to TEXT
 * nodes separated by soft breaks) and, if so, produces its HTML directly,
 * skipping inline parsing.
 *
 * Hard-wrapped prose is the common shape, so a block of several line pairs
 * stays on this path: each pair is scanned in place and the joints render as
 * the soft break's "\n". Nothing joins the lines before the scan.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class PlainInlineText
{
    public static function renderSource(InlineSourceView $source, CompiledProfile $profile): ?string
    {
        $pairs = $source->pairs;

        if ([] === $pairs) {
            return '';
        }

        $bytes = $source->buffer->bytes;
        $specials = $profile->plainTextSpecialBytes;

        // One line is the whole block for headings, table cells and any
        // paragraph the author did not wrap: keep it free of the loops the
        // multi-line shape needs.
        if (1 === \count($pairs)) {
            [$start, $end] = $pairs[0];
            $length = $end - $start;

            if ($length > 0 && strcspn($bytes, $specials, $start, $length) < $length) {
                return null;
            }

            foreach ($profile->contentScanTriggers as $trigger) {
                if (substr_count($bytes, $trigger, $start, $length) > 0) {
                    return null;
                }
            }

            if (Instrumentation::$timing) {
                ++Instrumentation::$plainScanHits;
            }

            return HtmlEscaper::text(trim($source->buffer->substring($start, $end), " \t"));
        }

        // One jump per line first: a special byte is the overwhelmingly
        // common reason to decline, and finding it here spares the trigger
        // substrings a pass over the lines that came before it.
        foreach ($pairs as [$start, $end]) {
            $length = $end - $start;

            if ($length > 0 && strcspn($bytes, $specials, $start, $length) < $length) {
                return null;
            }
        }

        foreach ($profile->contentScanTriggers as $trigger) {
            foreach ($pairs as [$start, $end]) {
                if (substr_count($bytes, $trigger, $start, $end - $start) > 0) {
                    return null;
                }
            }
        }

        return self::joinLines($bytes, $pairs);
    }

    public static function render(string $text, CompiledProfile $profile): ?string
    {
        if ('' === $text) {
            return '';
        }

        if (false !== strpbrk($text, $profile->plainTextSpecialBytes)) {
            return null;
        }

        foreach ($profile->contentScanTriggers as $trigger) {
            if (str_contains($text, $trigger)) {
                return null;
            }
        }

        if (Instrumentation::$timing) {
            ++Instrumentation::$plainScanHits;
        }

        return HtmlEscaper::text(trim($text, " \t"));
    }

    /**
     * Emits the already-scanned line pairs as one escaped run, mirroring what
     * the inline parser would produce for a block whose only inline nodes are
     * text and soft breaks.
     *
     * Whitespace semantics copied from InlineContent::fromPairs() and the
     * parser loop:
     *
     * - a joint strips the trailing U+0020 run of the line before it, and only
     *   U+0020: a trailing tab is literal content that ends the run;
     * - a run of two or more trailing spaces, or a trailing backslash, is a
     *   hard break, which can render "<br />", so it stays on the rich path;
     * - every line has its leading spaces and tabs dropped (the block parser
     *   already removes them from the pair, so this only guards odd pairs);
     * - the last line also has its trailing spaces and tabs dropped, tab
     *   included, because that is block-final layout rather than a joint.
     *
     * @param list<array{int, int, int}> $pairs
     */
    private static function joinLines(string $bytes, array $pairs): ?string
    {
        $last = \count($pairs) - 1;
        $text = '';

        foreach ($pairs as $index => [$start, $end]) {
            if ($index !== $last) {
                $spaces = 0;

                while ($end - $spaces > $start && ' ' === $bytes[$end - 1 - $spaces]) {
                    ++$spaces;
                }

                if ($spaces >= 2) {
                    return null;
                }

                $end -= $spaces;
            } else {
                while ($end > $start && (' ' === $bytes[$end - 1] || "\t" === $bytes[$end - 1])) {
                    --$end;
                }
            }

            while ($start < $end && (' ' === $bytes[$start] || "\t" === $bytes[$start])) {
                ++$start;
            }

            if ($index > 0) {
                $text .= "\n";
            }

            if ($end > $start) {
                $text .= substr($bytes, $start, $end - $start);
            }
        }

        if (Instrumentation::$timing) {
            ++Instrumentation::$plainScanHits;
        }

        return HtmlEscaper::text($text);
    }
}
