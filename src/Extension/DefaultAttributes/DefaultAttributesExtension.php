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

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DefaultAttributesExtension implements HtmlDecoratorExtensionInterface
{
    /**
     * Kinds that render an element owned by Alto. Raw HTML, plain text, soft
     * breaks, and reference definitions deliberately have no target here.
     *
     * @var array<string, string|null>
     */
    private const array TARGET_TAGS = [
        'paragraph' => null,
        'atx-heading' => null,
        'setext-heading' => null,
        'indented-code' => 'code',
        'fenced-code' => 'code',
        'block-quote' => null,
        'list' => null,
        'list-item' => null,
        'thematic-break' => null,
        'hard-break' => null,
        'code-span' => null,
        'emphasis' => null,
        'strong' => null,
        'link' => null,
        'image' => null,
        'autolink' => null,
        'strikethrough' => null,
        'gfm:table' => null,
        'github:alert' => null,
    ];

    /**
     * @var array<string, array<string, bool|string>>
     */
    private array $attributes;

    /**
     * @param array<string, array<string, bool|string|list<string>>> $attributes
     */
    public function __construct(array $attributes)
    {
        $normalized = [];

        foreach ($attributes as $kind => $values) {
            if (!\is_string($kind) || !\array_key_exists($kind, self::TARGET_TAGS)) {
                throw new InvalidExtensionException(\sprintf('Default attributes target unsupported native element kind "%s".', (string) $kind));
            }

            $normalized[$kind] = self::normalizeAttributes($kind, $values);
        }

        $this->attributes = $normalized;
    }

    public function name(): string
    {
        return 'default-attributes';
    }

    public function htmlDecorators(): iterable
    {
        foreach ($this->attributes as $kind => $attributes) {
            if ([] === $attributes) {
                continue;
            }

            yield HtmlDecoratorDefinition::node(
                $kind,
                new DefaultAttributesDecorator($attributes, self::TARGET_TAGS[$kind]),
                -1000,
            );
        }
    }

    /**
     * @param array<string, bool|string|list<string>> $attributes
     *
     * @return array<string, bool|string>
     */
    private static function normalizeAttributes(string $kind, array $attributes): array
    {
        $normalized = [];
        $names = [];

        foreach ($attributes as $name => $value) {
            if (!\is_string($name) || 1 !== preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]*$/D', $name)) {
                throw new InvalidExtensionException(\sprintf('Default HTML attribute name "%s" for "%s" is invalid.', (string) $name, $kind));
            }

            $lower = strtolower($name);
            if (isset($names[$lower])) {
                throw new InvalidExtensionException(\sprintf('Default HTML attribute "%s" is duplicated with different casing for "%s".', $name, $kind));
            }
            $names[$lower] = true;

            if (\is_array($value)) {
                if ('class' !== $lower || !array_is_list($value)) {
                    throw new InvalidExtensionException(\sprintf('Only the class attribute for "%s" accepts a list of strings.', $kind));
                }

                $classes = [];
                foreach ($value as $class) {
                    if (!\is_string($class)) {
                        throw new InvalidExtensionException(\sprintf('Default HTML classes for "%s" must be strings.', $kind));
                    }
                    if ('' !== $class && !\in_array($class, $classes, true)) {
                        $classes[] = $class;
                    }
                }

                $normalized[$lower] = implode(' ', $classes);

                continue;
            }

            if (!\is_string($value) && !\is_bool($value)) {
                throw new InvalidExtensionException(\sprintf('Default HTML attribute "%s" for "%s" must be a string or boolean.', $name, $kind));
            }

            $normalized[$lower] = 'class' === $lower && \is_string($value)
                ? self::normalizeClasses($value)
                : $value;
        }

        return $normalized;
    }

    private static function normalizeClasses(string $value): string
    {
        $classes = [];
        $candidates = preg_split('/\s+/', trim($value), -1, \PREG_SPLIT_NO_EMPTY);

        foreach (false === $candidates ? [] : $candidates as $class) {
            if (!\in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        return implode(' ', $classes);
    }
}
