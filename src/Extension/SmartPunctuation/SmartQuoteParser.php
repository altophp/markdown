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

use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SmartQuoteParser implements InlineParser
{
    private const int OTHER = 0;
    private const int WHITESPACE = 1;
    private const int PUNCTUATION = 2;

    private function __construct(
        private string $quote,
        private string $opener,
        private string $closer,
        private string $unpaired,
    ) {}

    public static function double(string $opener, string $closer): self
    {
        return new self('"', $opener, $closer, $opener);
    }

    public static function single(string $opener, string $closer): self
    {
        return new self("'", $opener, $closer, $closer);
    }

    public function triggerByte(): string
    {
        return $this->quote;
    }

    public function tryParse(InlineParseContext $context): InlineParseResult
    {
        $offset = $context->offset();
        $before = self::before($context, $offset);
        $after = self::after($context, $offset + 1);

        $leftFlanking = self::WHITESPACE !== $after
            && !(self::PUNCTUATION === $after && self::OTHER === $before);
        $rightFlanking = self::WHITESPACE !== $before
            && !(self::PUNCTUATION === $before && self::OTHER === $after);

        $replacement = match (true) {
            $rightFlanking => $this->closer,
            $leftFlanking => $this->opener,
            default => $this->unpaired,
        };

        return new InlineParseResult(
            $offset + 1,
            new InlineNode($replacement),
        );
    }

    private static function before(InlineParseContext $context, int $offset): int
    {
        if (0 === $offset) {
            return self::WHITESPACE;
        }

        $start = $offset - 1;
        $byte = $context->byteAt($start);

        if ($byte < 0x80) {
            return self::ascii($byte);
        }

        while ($start > 0 && 0x80 === ($context->byteAt($start) & 0xC0)) {
            --$start;
        }

        return self::multibyte($context->slice($start, $offset));
    }

    private static function after(InlineParseContext $context, int $offset): int
    {
        if ($offset >= $context->length()) {
            return self::WHITESPACE;
        }

        $byte = $context->byteAt($offset);
        if ($byte < 0x80) {
            return self::ascii($byte);
        }

        $length = match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            default => 2,
        };

        return self::multibyte($context->slice(
            $offset,
            min($offset + $length, $context->length()),
        ));
    }

    private static function ascii(int $byte): int
    {
        if (0x20 === $byte || 0x09 === $byte || 0x0A === $byte || 0x0C === $byte || 0x0D === $byte) {
            return self::WHITESPACE;
        }

        if (($byte >= 0x21 && $byte <= 0x2F)
            || ($byte >= 0x3A && $byte <= 0x40)
            || ($byte >= 0x5B && $byte <= 0x60)
            || ($byte >= 0x7B && $byte <= 0x7E)
        ) {
            return self::PUNCTUATION;
        }

        return self::OTHER;
    }

    private static function multibyte(string $character): int
    {
        if (1 === preg_match('/^\p{Zs}$/u', $character)) {
            return self::WHITESPACE;
        }

        if (1 === preg_match('/^[\p{P}\p{S}]$/u', $character)) {
            return self::PUNCTUATION;
        }

        return self::OTHER;
    }
}
