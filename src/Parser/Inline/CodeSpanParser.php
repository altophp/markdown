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
 * Code spans (CommonMark 0.31.2, "Code spans"). An opening backtick run of
 * length N is closed by the next run of EXACTLY N backticks; runs of other
 * lengths are skipped. With no closer the run stays literal text, so the
 * cursor is left untouched (return false) and the loop folds the backtick
 * into the current text run. Content is taken from the joined inline string
 * (joints are "\n"), line endings become spaces, and one space is stripped
 * from each end when both ends are spaces and the content is not all spaces.
 * Backslashes and entities stay raw: the whole span is consumed here before
 * those constructs ever see the bytes.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class CodeSpanParser implements InlineConstruct
{
    public function triggerBytes(): string
    {
        return '`';
    }

    public function tryParse(InlineScanState $state): bool
    {
        $text = $state->content()->text;
        $length = \strlen($text);
        $openStart = $state->offset();

        // A backtick string is maximal: a run preceded by a backtick is not
        // a fresh opening. The loop reaches interior ticks after a failed
        // run advances one byte; rejecting them keeps that run literal.
        if ($openStart > 0 && '`' === $text[$openStart - 1]) {
            return false;
        }

        $openEnd = $openStart + strspn($text, '`', $openStart);
        $size = $openEnd - $openStart;
        $scan = $openEnd;

        while (false !== ($next = strpos($text, '`', $scan))) {
            $runEnd = $next + strspn($text, '`', $next);

            if ($runEnd - $next === $size) {
                $content = $this->normalize(substr($text, $openEnd, $next - $openEnd));
                $state->emit(InlineKind::CODE_SPAN, $runEnd, 0, $content);

                return true;
            }

            $scan = $runEnd;
        }

        return false;
    }

    /**
     * Line endings to spaces, then strip one space from each end when both
     * ends are spaces and the content is not entirely spaces.
     */
    private function normalize(string $content): string
    {
        $content = str_replace("\n", ' ', $content);
        $length = \strlen($content);

        if ($length >= 2 && ' ' === $content[0] && ' ' === $content[$length - 1] && strspn($content, ' ') !== $length) {
            return substr($content, 1, -1);
        }

        return $content;
    }
}
