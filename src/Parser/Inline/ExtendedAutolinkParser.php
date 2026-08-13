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
 * GFM bare autolinks for `www.`, `http://`, `https://`, `ftp://`, and
 * email addresses. CommonMark angle-bracket autolinks stay in AutolinkParser.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ExtendedAutolinkParser implements ContentScannedInlineConstruct
{
    private const string TRIGGERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-+';
    private const string TRAILING_PUNCTUATION = '?!.,:*_~';

    /**
     * Per-needle search memo. Purely an accelerator: every read is
     * re-validated against the text and the search origin, so a stale entry
     * can only cost a fresh search, never change a result. It exists to keep
     * the candidate walk linear: without it, each declined candidate would
     * re-scan the whole block for the two needles that did not win.
     */
    private string $memoText = '';

    private int $memoFrom = 0;

    private int $memoWww = -1;

    private int $memoScheme = -1;

    private int $memoEmail = -1;

    public function triggerBytes(): string
    {
        return self::TRIGGERS;
    }

    /**
     * @return list<string>
     */
    public function contentScanTriggers(): array
    {
        return ['www.', '://', '@'];
    }

    /**
     * Every match starts at one of three shapes, so three substring searches
     * find them all:
     *
     * - a "www." label, whose match starts on the first "w";
     * - a "://" mark, whose match starts on the scheme in front of it;
     * - an "@", whose match starts at the head of the local part running
     *   back from it.
     *
     * The local-part walk reproduces what the byte-by-byte scanner used to
     * find: it stops where the run stops, never reaches behind the cursor,
     * and skips a leading "_" because "_" is an emphasis delimiter the
     * scanner claims before any construct sees it.
     */
    public function nextCandidate(string $text, int $from): int
    {
        if ($this->memoText !== $text || $from < $this->memoFrom) {
            $this->memoText = $text;
            $this->memoWww = -2;
            $this->memoScheme = -2;
            $this->memoEmail = -2;
        }

        $this->memoFrom = $from;

        if (-2 === $this->memoWww || ($this->memoWww >= 0 && $this->memoWww < $from)) {
            $at = strpos($text, 'www.', $from);
            $this->memoWww = false === $at ? -1 : $at;
        }

        if (-2 === $this->memoScheme || ($this->memoScheme >= 0 && $this->memoScheme < $from)) {
            $this->memoScheme = $this->nextSchemeStart($text, $from);
        }

        if (-2 === $this->memoEmail || ($this->memoEmail >= 0 && $this->memoEmail < $from)) {
            $this->memoEmail = $this->nextEmailStart($text, $from);
        }

        $best = $this->memoWww;

        if ($this->memoScheme >= 0 && ($best < 0 || $this->memoScheme < $best)) {
            $best = $this->memoScheme;
        }

        if ($this->memoEmail >= 0 && ($best < 0 || $this->memoEmail < $best)) {
            $best = $this->memoEmail;
        }

        return $best;
    }

    /**
     * A scheme whose "://" sits behind $from cannot start a match at or
     * after $from, so those marks are skipped rather than clamped.
     */
    private function nextSchemeStart(string $text, int $from): int
    {
        $mark = $from;

        while (false !== ($mark = strpos($text, '://', $mark))) {
            if ($mark >= $from + 5 && 'https' === substr($text, $mark - 5, 5)) {
                return $mark - 5;
            }

            if ($mark >= $from + 4 && 'http' === substr($text, $mark - 4, 4)) {
                return $mark - 4;
            }

            if ($mark >= $from + 3 && 'ftp' === substr($text, $mark - 3, 3)) {
                return $mark - 3;
            }

            ++$mark;
        }

        return -1;
    }

    /**
     * A local part is clamped at $from rather than skipped: the scanner used
     * to resume inside a run whenever a construct ended in the middle of one
     * (a backslash escape of "." for instance), and matched from there.
     */
    private function nextEmailStart(string $text, int $from): int
    {
        $mark = $from;

        while (false !== ($mark = strpos($text, '@', $mark))) {
            $start = $mark;

            while ($start > $from && $this->isEmailLocal(\ord($text[$start - 1]))) {
                --$start;
            }

            while ($start < $mark && '_' === $text[$start]) {
                ++$start;
            }

            if ($start < $mark) {
                return $start;
            }

            ++$mark;
        }

        return -1;
    }

    public function tryParse(InlineScanState $state): bool
    {
        $url = $this->urlAt($state->content()->text, $state->offset());

        if (null !== $url) {
            [$end, $href] = $url;
            $state->emit(InlineKind::AUTOLINK, $end, 0, $href);

            return true;
        }

        $email = $this->emailAt($state->content()->text, $state->offset());

        if (null !== $email) {
            [$end, $href] = $email;
            $state->emit(InlineKind::AUTOLINK, $end, 0, $href);

            return true;
        }

        return false;
    }

    /**
     * @return array{int, string}|null
     */
    private function urlAt(string $text, int $start): ?array
    {
        if (!$this->hasUrlBoundary($text, $start)) {
            return null;
        }

        $prefix = '';
        $domainStart = $start;

        if (str_starts_with(substr($text, $start), 'www.')) {
            $prefix = 'http://';
            $domainStart = $start + 4;
        } elseif (str_starts_with(substr($text, $start), 'http://')) {
            $domainStart = $start + 7;
        } elseif (str_starts_with(substr($text, $start), 'https://')) {
            $domainStart = $start + 8;
        } elseif (str_starts_with(substr($text, $start), 'ftp://')) {
            $domainStart = $start + 6;
        } else {
            return null;
        }

        $domain = $this->domainEnd($text, $domainStart, true);

        if (null === $domain) {
            return null;
        }

        [$domainEnd] = $domain;
        $end = $this->pathEnd($text, $domainEnd);
        $end = $this->trimPath($text, $start, $end);

        $label = substr($text, $start, $end - $start);

        return [$end, Href::encode($prefix . $label)];
    }

    /**
     * @return array{int, string}|null
     */
    private function emailAt(string $text, int $start): ?array
    {
        $length = \strlen($text);
        $at = $start;

        while ($at < $length && $this->isEmailLocal(\ord($text[$at]))) {
            ++$at;
        }

        if ($at === $start || $at >= $length || '@' !== $text[$at]) {
            return null;
        }

        $domain = $this->domainEnd($text, $at + 1, false);

        if (null === $domain) {
            return null;
        }

        [$end] = $domain;
        $label = substr($text, $start, $end - $start);

        return [$end, Href::encode('mailto:' . $label)];
    }

    private function hasUrlBoundary(string $text, int $start): bool
    {
        if (0 === $start) {
            return true;
        }

        $previous = $text[$start - 1];

        return ' ' === $previous
            || "\t" === $previous
            || "\n" === $previous
            || '*' === $previous
            || '_' === $previous
            || '~' === $previous
            || '(' === $previous;
    }

    /**
     * @return array{int, list<string>}|null
     */
    private function domainEnd(string $text, int $start, bool $url): ?array
    {
        $length = \strlen($text);
        $offset = $start;
        $segments = [];

        while ($offset < $length) {
            $segmentStart = $offset;

            while ($offset < $length && $this->isDomainByte(\ord($text[$offset]))) {
                ++$offset;
            }

            if ($offset === $segmentStart) {
                break;
            }

            $segments[] = substr($text, $segmentStart, $offset - $segmentStart);

            if ($offset >= $length || '.' !== $text[$offset] || $offset + 1 >= $length || !$this->isDomainByte(\ord($text[$offset + 1]))) {
                break;
            }

            ++$offset;
        }

        if (\count($segments) < 2) {
            return null;
        }

        if ($url) {
            $lastTwo = \array_slice($segments, -2);

            foreach ($lastTwo as $segment) {
                if (str_contains($segment, '_')) {
                    return null;
                }
            }
        } else {
            $last = $segments[\count($segments) - 1];
            $lastByte = $last[\strlen($last) - 1];

            if ('-' === $lastByte || '_' === $lastByte) {
                return null;
            }
        }

        return [$offset, $segments];
    }

    private function pathEnd(string $text, int $start): int
    {
        $offset = $start;
        $length = \strlen($text);

        while ($offset < $length) {
            $byte = \ord($text[$offset]);

            if ($byte <= 0x20 || 0x7F === $byte || 0x3C === $byte) {
                break;
            }

            ++$offset;
        }

        return $offset;
    }

    private function trimPath(string $text, int $start, int $end): int
    {
        while ($end > $start && str_contains(self::TRAILING_PUNCTUATION, $text[$end - 1])) {
            --$end;
        }

        $candidate = substr($text, $start, $end - $start);

        if (1 === preg_match('/&[A-Za-z0-9]+;$/', $candidate, $match)) {
            $end -= \strlen($match[0]);
            $candidate = substr($text, $start, $end - $start);
        }

        while ($end > $start && ')' === $text[$end - 1] && substr_count($candidate, ')') > substr_count($candidate, '(')) {
            --$end;
            $candidate = substr($text, $start, $end - $start);
        }

        return $end;
    }

    private function isEmailLocal(int $byte): bool
    {
        return $this->isAsciiAlnum($byte)
            || 0x2E === $byte
            || 0x2D === $byte
            || 0x5F === $byte
            || 0x2B === $byte;
    }

    private function isDomainByte(int $byte): bool
    {
        return $this->isAsciiAlnum($byte)
            || 0x2D === $byte
            || 0x5F === $byte;
    }

    private function isAsciiAlnum(int $byte): bool
    {
        return ($byte >= 0x30 && $byte <= 0x39)
            || ($byte >= 0x41 && $byte <= 0x5A)
            || ($byte >= 0x61 && $byte <= 0x7A);
    }
}
