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

namespace Alto\Markdown\Extension\DefaultAttributes;

use Alto\Markdown\Extension\Html\HtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DefaultAttributesDecorator implements HtmlNodeDecorator
{
    /**
     * @param array<string, bool|string> $attributes
     */
    public function __construct(
        private array $attributes,
        private ?string $targetTag,
    ) {
    }

    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        $opening = $this->openingTag($html);
        if (null === $opening) {
            return $html;
        }

        [$start, $end] = $opening;
        $tag = substr($html, $start, $end - $start + 1);
        $append = '';

        foreach ($this->attributes as $name => $value) {
            if ('class' === $name) {
                $tag = $this->mergeClass($context, $tag, $value);

                continue;
            }

            if ($this->hasAttribute($tag, $name) || false === $value) {
                continue;
            }

            $append .= true === $value
                ? ' '.$name
                : ' '.$name.'="'.$this->escapeValue($context, $name, $value).'"';
        }

        if ('' !== $append) {
            $tag = $this->appendBeforeClose($tag, $append);
        }

        return substr($html, 0, $start).$tag.substr($html, $end + 1);
    }

    /**
     * @return array{int, int}|null
     */
    private function openingTag(string $html): ?array
    {
        $pattern = null === $this->targetTag
            ? '/\\A<[A-Za-z][A-Za-z0-9:-]*(?=[\\s>\\/])/'
            : '/<'.preg_quote($this->targetTag, '/').'(?=[\\s>\\/])/i';

        if (1 !== preg_match($pattern, $html, $matches, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $matches[0][1];
        $end = strpos($html, '>', $start + \strlen($matches[0][0]));

        return false === $end ? null : [$start, $end];
    }

    private function mergeClass(HtmlNodeOutputContext $context, string $tag, string|bool $value): string
    {
        if (false === $value || '' === $value) {
            return $tag;
        }

        $class = true === $value ? '' : $context->escapeAttribute($value);
        $pattern = "/(?:\"[^\"]*\"|'[^']*')(*SKIP)(*F)|"
            ."\\sclass(?:\\s*=\\s*(?:\"([^\"]*)\"|'([^']*)'|([^\\s>]+)))?/i";

        if (1 !== preg_match($pattern, $tag, $matches, \PREG_OFFSET_CAPTURE)) {
            return $this->appendBeforeClose(
                $tag,
                true === $value ? ' class' : ' class="'.$class.'"',
            );
        }

        if (true === $value) {
            return $tag;
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
        $candidates = preg_split('/\s+/', trim($class.' '.$existing), -1, \PREG_SPLIT_NO_EMPTY);
        foreach (false === $candidates ? [] : $candidates as $candidate) {
            if (!\in_array($candidate, $classes, true)) {
                $classes[] = $candidate;
            }
        }
        $merged = implode(' ', $classes);
        $at = $matches[0][1];
        $length = \strlen($matches[0][0]);

        return substr($tag, 0, $at).' class="'.$merged.'"'.substr($tag, $at + $length);
    }

    private function appendBeforeClose(string $tag, string $attribute): string
    {
        $insert = \strlen($tag) - 1;
        if ('/' === $tag[$insert - 1]) {
            --$insert;
            while ($insert > 0 && ctype_space($tag[$insert - 1])) {
                --$insert;
            }
        }

        return substr($tag, 0, $insert).$attribute.substr($tag, $insert);
    }

    private function hasAttribute(string $tag, string $name): bool
    {
        return 1 === preg_match(
            "/(?:\"[^\"]*\"|'[^']*')(*SKIP)(*F)|"
            .'\\s'.preg_quote($name, '/').'(?=\\s|=|\\/?>)/i',
            $tag,
        );
    }

    private function escapeValue(HtmlNodeOutputContext $context, string $name, string $value): string
    {
        return \in_array($name, ['href', 'src'], true)
            ? $context->escapeUrl($value)
            : $context->escapeAttribute($value);
    }
}
