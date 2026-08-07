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

namespace Alto\Markdown\Extension\Attributes;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class AttributesHtmlInjector
{
    /**
     * @var array<string, true>
     */
    private const array VOID_ELEMENTS = [
        'area' => true,
        'base' => true,
        'br' => true,
        'col' => true,
        'embed' => true,
        'hr' => true,
        'img' => true,
        'input' => true,
        'link' => true,
        'meta' => true,
        'param' => true,
        'source' => true,
        'track' => true,
        'wbr' => true,
    ];

    /**
     * @param array<string, true|string>        $attributes
     * @param \Closure(string, string): ?string $escape
     */
    public function first(string $html, array $attributes, \Closure $escape): string
    {
        if ([] === $attributes || 1 !== preg_match('/\A\s*<[A-Za-z][A-Za-z0-9:-]*(?=[\s>\/])/', $html, $match, \PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $start = $match[0][1] + strspn($match[0][0], " \t\r\n\f");
        $end = $this->tagEnd($html, $start);

        return null === $end ? $html : $this->inject($html, $start, $end, $attributes, $escape);
    }

    /**
     * @param array<string, true|string>        $attributes
     * @param \Closure(string, string): ?string $escape
     */
    public function last(string $html, array $attributes, \Closure $escape): ?string
    {
        if ([] === $attributes) {
            return $html;
        }

        $length = \strlen($html);
        while ($length > 0 && ctype_space($html[$length - 1])) {
            --$length;
        }

        $tokens = $this->tags(substr($html, 0, $length));
        $last = array_pop($tokens);

        if (null === $last || $last['end'] !== $length) {
            return null;
        }

        if (!$last['closing']) {
            return $last['void']
                ? $this->inject($html, $last['start'], $last['end'], $attributes, $escape)
                : null;
        }

        $depth = 1;
        $opening = null;

        while (null !== ($token = array_pop($tokens))) {
            if ($token['name'] !== $last['name'] || $token['void']) {
                continue;
            }

            $depth += $token['closing'] ? 1 : -1;
            if (0 === $depth) {
                $opening = $token;

                break;
            }
        }

        return null === $opening
            ? null
            : $this->inject($html, $opening['start'], $opening['end'], $attributes, $escape);
    }

    /**
     * @return list<array{start: int, end: int, name: string, closing: bool, void: bool}>
     */
    private function tags(string $html): array
    {
        $tokens = [];
        $length = \strlen($html);
        $offset = 0;

        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if (false === $start || $start + 1 >= $length) {
                break;
            }

            $cursor = $start + 1;
            $closing = '/' === $html[$cursor];
            if ($closing) {
                ++$cursor;
            }

            if ($cursor >= $length || 1 !== preg_match('/[A-Za-z]/', $html[$cursor])) {
                $offset = $start + 1;

                continue;
            }

            $nameStart = $cursor;
            while ($cursor < $length && 1 === preg_match('/[A-Za-z0-9:-]/', $html[$cursor])) {
                ++$cursor;
            }
            $name = strtolower(substr($html, $nameStart, $cursor - $nameStart));
            $end = $this->tagEnd($html, $start);
            if (null === $end) {
                break;
            }

            $beforeClose = $end - 2;
            while ($beforeClose > $cursor && ctype_space($html[$beforeClose])) {
                --$beforeClose;
            }
            $void = !$closing && ('/' === $html[$beforeClose] || isset(self::VOID_ELEMENTS[$name]));
            $tokens[] = [
                'start' => $start,
                'end' => $end,
                'name' => $name,
                'closing' => $closing,
                'void' => $void,
            ];
            $offset = $end;
        }

        return $tokens;
    }

    private function tagEnd(string $html, int $start): ?int
    {
        $length = \strlen($html);
        $quote = null;

        for ($offset = $start + 1; $offset < $length; ++$offset) {
            $byte = $html[$offset];

            if (null !== $quote) {
                if ($byte === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ('"' === $byte || "'" === $byte) {
                $quote = $byte;

                continue;
            }

            if ('>' === $byte) {
                return $offset + 1;
            }
        }

        return null;
    }

    /**
     * @param array<string, true|string>        $attributes
     * @param \Closure(string, string): ?string $escape
     */
    private function inject(
        string $html,
        int $start,
        int $end,
        array $attributes,
        \Closure $escape,
    ): string {
        $tag = substr($html, $start, $end - $start);
        $append = '';

        foreach ($attributes as $name => $value) {
            if ('class' === $name && \is_string($value)) {
                $tag = $this->mergeClass($tag, $value, $escape);

                continue;
            }

            if ($this->hasAttribute($tag, $name)) {
                continue;
            }

            if (true === $value) {
                $append .= ' '.$name;

                continue;
            }

            $escaped = $escape($name, $value);
            if (null !== $escaped) {
                $append .= ' '.$name.'="'.$escaped.'"';
            }
        }

        if ('' !== $append) {
            $tag = $this->appendBeforeClose($tag, $append);
        }

        return substr($html, 0, $start).$tag.substr($html, $end);
    }

    /**
     * @param \Closure(string, string): ?string $escape
     */
    private function mergeClass(string $tag, string $value, \Closure $escape): string
    {
        $escaped = $escape('class', $value);
        if (null === $escaped || '' === $escaped) {
            return $tag;
        }

        $pattern = "/(?:\"[^\"]*\"|'[^']*')(*SKIP)(*F)|"
            ."\\sclass(?:\\s*=\\s*(?:\"([^\"]*)\"|'([^']*)'|([^\\s>]+)))?/i";

        if (1 !== preg_match($pattern, $tag, $matches, \PREG_OFFSET_CAPTURE)) {
            return $this->appendBeforeClose($tag, ' class="'.$escaped.'"');
        }

        $existing = '';
        foreach ([1, 2, 3] as $index) {
            $candidate = $matches[$index] ?? null;
            if (null !== $candidate && -1 !== $candidate[1]) {
                $existing = $candidate[0];

                break;
            }
        }

        $classes = [];
        $candidates = preg_split('/\s+/', trim($existing.' '.$escaped), -1, \PREG_SPLIT_NO_EMPTY);
        foreach (false === $candidates ? [] : $candidates as $class) {
            $classes[$class] = true;
        }
        $at = $matches[0][1];

        return substr($tag, 0, $at)
            .' class="'.implode(' ', array_keys($classes)).'"'
            .substr($tag, $at + \strlen($matches[0][0]));
    }

    private function appendBeforeClose(string $tag, string $attributes): string
    {
        $insert = \strlen($tag) - 1;
        if ('/' === $tag[$insert - 1]) {
            --$insert;
            while ($insert > 0 && ctype_space($tag[$insert - 1])) {
                --$insert;
            }
        }

        return substr($tag, 0, $insert).$attributes.substr($tag, $insert);
    }

    private function hasAttribute(string $tag, string $name): bool
    {
        return 1 === preg_match(
            "/(?:\"[^\"]*\"|'[^']*')(*SKIP)(*F)|"
            .'\\s'.preg_quote($name, '/').'(?=\\s|=|\\/?>)/i',
            $tag,
        );
    }
}
