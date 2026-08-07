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
 * Inline backslash escapes (CommonMark 0.31.2, "Backslash escapes").
 * A backslash before an ASCII punctuation byte escapes it: both bytes
 * are consumed and emitted as one TEXT node whose payload is the single
 * escaped character. A backslash before anything else is a literal
 * backslash, so tryParse returns false and the byte joins the text run.
 * Backslash-before-newline hard breaks are stripped by InlineContent, so
 * this construct never sees a trailing break backslash.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BackslashEscapeParser implements InlineConstruct
{
    private const string ASCII_PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    public function triggerBytes(): string
    {
        return '\\';
    }

    public function tryParse(InlineScanState $state): bool
    {
        $text = $state->content()->text;
        $next = $state->offset() + 1;

        if ($next >= \strlen($text)) {
            return false;
        }

        $escaped = $text[$next];

        if (!str_contains(self::ASCII_PUNCTUATION, $escaped)) {
            return false;
        }

        $state->emit(InlineKind::TEXT, $next + 1, 0, $escaped);

        return true;
    }
}
