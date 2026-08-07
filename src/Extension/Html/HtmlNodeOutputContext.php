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

namespace Alto\Markdown\Extension\Html;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HtmlNodeOutputContext
{
    /**
     * @var array<string, bool|int|string|null>
     */
    private array $attributes;

    /**
     * @param array<string, bool|int|string|null> $attributes
     *
     * @internal Alto creates output contexts for registered decorators
     */
    public function __construct(
        public string $kind,
        public ?SourceRange $range,
        private string $source,
        private HtmlPolicy $policy,
        array $attributes = [],
        private ?int $ordinal = null,
    ) {
        foreach ($attributes as $name => $value) {
            if (!\is_string($name)) {
                throw new InvalidExtensionException('HTML node attribute names must be strings.');
            }
            if (!\is_bool($value) && !\is_int($value) && !\is_string($value) && null !== $value) {
                throw new InvalidExtensionException(\sprintf('HTML node attribute "%s" must be a boolean, integer, string, or null.', $name));
            }
        }

        $this->attributes = $attributes;
    }

    /**
     * Exact source bytes covered by this node in the current inline input or
     * original block input.
     */
    public function source(): string
    {
        return $this->source;
    }

    public function attribute(string $name): bool|int|string|null
    {
        if (!\array_key_exists($name, $this->attributes)) {
            throw new InvalidExtensionException(\sprintf('HTML node attribute "%s" is not defined.', $name));
        }

        return $this->attributes[$name];
    }

    public function bool(string $name): bool
    {
        $value = $this->attribute($name);

        if (!\is_bool($value)) {
            throw new InvalidExtensionException(\sprintf('HTML node attribute "%s" is not a boolean.', $name));
        }

        return $value;
    }

    public function int(string $name): int
    {
        $value = $this->attribute($name);

        if (!\is_int($value)) {
            throw new InvalidExtensionException(\sprintf('HTML node attribute "%s" is not an integer.', $name));
        }

        return $value;
    }

    public function string(string $name): string
    {
        $value = $this->attribute($name);

        if (!\is_string($value)) {
            throw new InvalidExtensionException(\sprintf('HTML node attribute "%s" is not a string.', $name));
        }

        return $value;
    }

    /**
     * Parse-tape ordinal when the context represents a block.
     *
     * @internal
     */
    public function ordinal(): ?int
    {
        return $this->ordinal;
    }

    public function escapeText(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    public function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    /**
     * Apply the active URL policy, then escape the value for an HTML
     * attribute. A refused URL becomes an empty string.
     */
    public function escapeUrl(string $value): string
    {
        if ($this->policy->filtersUrls && !$this->policy->allowsUrl($value)) {
            return '';
        }

        return $this->escapeAttribute($value);
    }
}
