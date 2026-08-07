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
final readonly class AttributeListParser
{
    public function __construct(private AttributesPolicy $policy)
    {
    }

    public function parsePrefix(string $source): ?ParsedAttributeList
    {
        $sourceLength = \strlen($source);

        if (0 === $sourceLength || '{' !== $source[0]) {
            return null;
        }

        $offset = 1;
        if ($offset < $sourceLength && ':' === $source[$offset]) {
            ++$offset;
        }

        $attributes = [];
        $count = 0;

        while ($offset < $sourceLength && $offset <= $this->policy->maxListBytes) {
            $this->skipWhitespace($source, $sourceLength, $offset);

            if ($offset >= $sourceLength || $offset > $this->policy->maxListBytes) {
                return null;
            }

            if ('}' === $source[$offset]) {
                if (0 === $count) {
                    return null;
                }

                return new ParsedAttributeList($offset + 1, $attributes);
            }

            ++$count;
            if ($count > $this->policy->maxAttributes) {
                return null;
            }

            $marker = $source[$offset];
            if ('.' === $marker || '#' === $marker) {
                $name = $this->shortcut($source, $sourceLength, $offset);
                if (null === $name) {
                    return null;
                }

                $attribute = '.' === $marker ? 'class' : 'id';
                if ($this->policy->allows($attribute)) {
                    $attributes = 'class' === $attribute
                        ? AttributeSet::merge($attributes, ['class' => $name])
                        : array_replace($attributes, ['id' => $name]);
                }

                continue;
            }

            $name = $this->name($source, $sourceLength, $offset);
            if (null === $name) {
                return null;
            }

            $this->skipHorizontalWhitespace($source, $sourceLength, $offset);
            if ($offset >= $sourceLength || '=' !== $source[$offset]) {
                return null;
            }
            ++$offset;
            $this->skipHorizontalWhitespace($source, $sourceLength, $offset);

            $value = $this->value($source, $sourceLength, $offset);
            if (null === $value || \strlen($value) > $this->policy->maxValueBytes) {
                return null;
            }

            $name = strtolower($name);
            if (!$this->policy->allows($name)) {
                continue;
            }

            if ('class' === $name) {
                $classes = $this->classes($value);
                if (null === $classes) {
                    return null;
                }
                $attributes = AttributeSet::merge($attributes, ['class' => $classes]);

                continue;
            }

            if ('id' === $name && 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]{0,63}$/D', $value)) {
                return null;
            }

            $attributes[$name] = 'true' === $value ? true : $value;
        }

        return null;
    }

    public function parseWhole(string $source): ?ParsedAttributeList
    {
        $parsed = $this->parsePrefix($source);

        if (null === $parsed || '' !== trim(substr($source, $parsed->length), " \t")) {
            return null;
        }

        return $parsed;
    }

    private function skipWhitespace(string $source, int $length, int &$offset): void
    {
        while ($offset < $length && (' ' === $source[$offset] || "\t" === $source[$offset])) {
            ++$offset;
        }
    }

    private function skipHorizontalWhitespace(string $source, int $length, int &$offset): void
    {
        $this->skipWhitespace($source, $length, $offset);
    }

    private function shortcut(string $source, int $length, int &$offset): ?string
    {
        ++$offset;
        $start = $offset;

        while ($offset < $length && 1 === preg_match('/[A-Za-z0-9_:-]/', $source[$offset])) {
            ++$offset;
        }

        $value = substr($source, $start, $offset - $start);

        if ('' === $value
            || \strlen($value) > 64
            || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_:-]*$/D', $value)
        ) {
            return null;
        }

        return $value;
    }

    private function name(string $source, int $length, int &$offset): ?string
    {
        $start = $offset;

        if (1 !== preg_match('/[A-Za-z_:]/', $source[$offset])) {
            return null;
        }
        ++$offset;

        while ($offset < $length && 1 === preg_match('/[A-Za-z0-9_.:-]/', $source[$offset])) {
            ++$offset;
        }

        $name = substr($source, $start, $offset - $start);

        return \strlen($name) <= 64 ? $name : null;
    }

    private function value(string $source, int $length, int &$offset): ?string
    {
        if ($offset >= $length) {
            return null;
        }

        $quote = $source[$offset];
        if ('"' !== $quote && "'" !== $quote) {
            $start = $offset;

            while ($offset < $length
                && '}' !== $source[$offset]
                && ' ' !== $source[$offset]
                && "\t" !== $source[$offset]
            ) {
                if (\ord($source[$offset]) < 0x20 || 0x7F === \ord($source[$offset])) {
                    return null;
                }
                ++$offset;
            }

            return $offset === $start ? null : substr($source, $start, $offset - $start);
        }

        ++$offset;
        $value = '';

        while ($offset < $length) {
            $byte = $source[$offset];

            if ($byte === $quote) {
                ++$offset;

                return $value;
            }

            if ('\\' === $byte && $offset + 1 < $length) {
                $next = $source[$offset + 1];
                if ($next === $quote || '\\' === $next) {
                    $value .= $next;
                    $offset += 2;

                    continue;
                }
            }

            if (\ord($byte) < 0x20 || 0x7F === \ord($byte)) {
                return null;
            }

            $value .= $byte;
            ++$offset;
        }

        return null;
    }

    private function classes(string $value): ?string
    {
        $classes = [];
        $candidates = preg_split('/\s+/', trim($value), -1, \PREG_SPLIT_NO_EMPTY);

        foreach (false === $candidates ? [] : $candidates as $class) {
            if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $class)) {
                return null;
            }

            $classes[$class] = true;
        }

        return [] === $classes ? null : implode(' ', array_keys($classes));
    }
}
