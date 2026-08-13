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

use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class PairedDelimiterParser implements InlineParser
{
    public function __construct(
        private string $opening,
        private string $closing,
    ) {}

    public function triggerByte(): string
    {
        return $this->opening[0];
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        $remaining = $context->remaining();
        if (!str_starts_with($remaining, $this->opening)) {
            return null;
        }

        $contentStart = \strlen($this->opening);
        $closing = strpos($remaining, $this->closing, $contentStart);
        if (false === $closing || $closing === $contentStart) {
            return null;
        }

        $text = substr($remaining, $contentStart, $closing - $contentStart);
        if (\strlen($text) !== strcspn($text, "\r\n")) {
            return null;
        }

        return new InlineParseResult(
            $context->offset() + $closing + \strlen($this->closing),
            new InlineNode($text),
        );
    }
}
