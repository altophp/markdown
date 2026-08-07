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

namespace Alto\Markdown\Extension\PairedDelimiter;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\InlineExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class PairedDelimiterExtension implements InlineExtensionInterface
{
    /**
     * @var array<string, true>
     */
    private const array ELEMENTS = [
        'abbr' => true,
        'b' => true,
        'cite' => true,
        'code' => true,
        'del' => true,
        'dfn' => true,
        'em' => true,
        'i' => true,
        'ins' => true,
        'kbd' => true,
        'mark' => true,
        'q' => true,
        's' => true,
        'samp' => true,
        'small' => true,
        'span' => true,
        'strike' => true,
        'strong' => true,
        'sub' => true,
        'sup' => true,
        'tt' => true,
        'var' => true,
    ];

    public function __construct(
        private string $name,
        private string $opening,
        private string $closing,
        private string $element = 'span',
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new InvalidExtensionException(\sprintf('Paired delimiter extension name "%s" must start with a lowercase letter and contain only lowercase letters, digits, and hyphens.', $name));
        }

        self::validateDelimiter($opening, 'opening');
        self::validateDelimiter($closing, 'closing');

        if (!isset(self::ELEMENTS[$element])) {
            throw new InvalidExtensionException(\sprintf('Paired delimiter element "%s" is not a supported safe inline element.', $element));
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function inlines(): iterable
    {
        $output = new PairedDelimiterOutput($this->element);

        yield new InlineDefinition(
            kind: 'span',
            parser: new PairedDelimiterParser($this->opening, $this->closing),
            html: $output,
            markdown: $output,
        );
    }

    private static function validateDelimiter(string $delimiter, string $position): void
    {
        $length = \strlen($delimiter);

        if ($length < 1 || $length > 16) {
            throw new InvalidExtensionException(\sprintf('Paired delimiter %s delimiter must contain 1 through 16 bytes.', $position));
        }

        if (1 === preg_match('/[\x00-\x20\x7F]/', $delimiter)) {
            throw new InvalidExtensionException(\sprintf('Paired delimiter %s delimiter must not contain whitespace or control bytes.', $position));
        }
    }
}
