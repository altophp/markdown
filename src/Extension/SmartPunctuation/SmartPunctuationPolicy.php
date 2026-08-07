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

namespace Alto\Markdown\Extension\SmartPunctuation;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SmartPunctuationPolicy
{
    public function __construct(
        public string $doubleQuoteOpener = "\u{201C}",
        public string $doubleQuoteCloser = "\u{201D}",
        public string $singleQuoteOpener = "\u{2018}",
        public string $singleQuoteCloser = "\u{2019}",
    ) {
        self::validateReplacement($doubleQuoteOpener, 'double quote opener');
        self::validateReplacement($doubleQuoteCloser, 'double quote closer');
        self::validateReplacement($singleQuoteOpener, 'single quote opener');
        self::validateReplacement($singleQuoteCloser, 'single quote closer');
    }

    private static function validateReplacement(string $replacement, string $name): void
    {
        if ('' === $replacement || 1 !== preg_match('//u', $replacement)) {
            throw new InvalidExtensionException(\sprintf('Smart punctuation %s must be a non-empty valid UTF-8 string.', $name));
        }
    }
}
