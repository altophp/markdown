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

namespace Alto\Markdown\Extension\Mention;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MentionDefinition
{
    private string $compiledPattern;

    public function __construct(
        public string $type,
        public string $prefix,
        public string $pattern,
        public MentionResolver $resolver,
        public int $maxIdentifierBytes = 128,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/D', $type)) {
            throw new InvalidExtensionException(\sprintf('Mention type "%s" must start with a lowercase letter and contain only lowercase letters, digits, and hyphens.', $type));
        }

        if ('' === $prefix) {
            throw new InvalidExtensionException('A mention prefix cannot be empty.');
        }

        if ($maxIdentifierBytes < 1) {
            throw new InvalidExtensionException('Mention maxIdentifierBytes must be at least 1.');
        }

        $this->compiledPattern = self::compilePattern($pattern);

        $emptyMatch = preg_match($this->compiledPattern, '', $matches);
        if (1 === $emptyMatch && '' === $matches[0]) {
            throw new InvalidExtensionException('A mention pattern cannot match an empty identifier.');
        }
    }

    public static function links(
        string $type,
        string $prefix,
        string $pattern,
        string $urlTemplate,
        int $maxIdentifierBytes = 128,
    ): self {
        return new self(
            $type,
            $prefix,
            $pattern,
            new UrlTemplateMentionResolver($urlTemplate),
            $maxIdentifierBytes,
        );
    }

    /**
     * @internal Used by the compiled mention parser
     */
    public function compiledPattern(): string
    {
        return $this->compiledPattern;
    }

    private static function compilePattern(string $pattern): string
    {
        foreach (['~', '#', '%', '!', ';', "\x01"] as $delimiter) {
            if (!str_contains($pattern, $delimiter)) {
                $compiled = $delimiter.'\A(?:'.$pattern.')'.$delimiter.'i';
                if (false === @preg_match($compiled, '')) {
                    throw new InvalidExtensionException('Mention pattern must be a valid PCRE expression.');
                }

                return $compiled;
            }
        }

        throw new InvalidExtensionException('Mention pattern contains too many regular expression delimiters.');
    }
}
