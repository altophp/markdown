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

namespace Alto\Markdown\Tests\Extension\Fixture;

use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;

final readonly class PublicMarkParser implements InlineParser
{
    public function triggerByte(): string
    {
        return '^';
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        $remaining = $context->remaining();

        if (!str_starts_with($remaining, '^^')) {
            return null;
        }

        $close = strpos($remaining, '^^', 2);

        if (false === $close || 2 === $close) {
            return null;
        }

        $text = substr($remaining, 2, $close - 2);

        return new InlineParseResult(
            $context->offset() + $close + 2,
            new InlineNode($text, ['label' => $text]),
        );
    }
}
