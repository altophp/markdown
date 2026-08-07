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

namespace Alto\Markdown\Extension\Inline;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineLinkSemantics
{
    public function __construct(
        public string $destinationAttribute,
        public ?string $titleAttribute = null,
    ) {
        self::validateAttribute($destinationAttribute);

        if (null !== $titleAttribute) {
            self::validateAttribute($titleAttribute);
        }
    }

    /**
     * @return array{destination: string, title?: string|null}
     *
     * @internal
     */
    public function attributes(InlineNode $node): array
    {
        $attributes = ['destination' => $node->string($this->destinationAttribute)];

        if (null !== $this->titleAttribute) {
            $title = $node->attribute($this->titleAttribute);
            if (null !== $title && !\is_string($title)) {
                throw new InvalidExtensionException(\sprintf('Inline link title attribute "%s" is not a string or null.', $this->titleAttribute));
            }

            $attributes['title'] = $title;
        }

        return $attributes;
    }

    private static function validateAttribute(string $attribute): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/D', $attribute)) {
            throw new InvalidExtensionException(\sprintf('Inline link attribute "%s" must start with a lowercase letter and contain only lowercase letters, digits, and hyphens.', $attribute));
        }
    }
}
