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
final readonly class SmartDashParser implements InlineParser
{
    public function triggerByte(): string
    {
        return '-';
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        $offset = $context->offset();

        if ($offset > 0 && '-' === $context->slice($offset - 1, $offset)) {
            return null;
        }

        $count = strspn($context->remaining(), '-');
        if ($count < 2) {
            return null;
        }

        [$emCount, $enCount] = self::replacementCounts($count);

        return new InlineParseResult(
            $offset + $count,
            new InlineNode(
                str_repeat("\u{2014}", $emCount)
                .str_repeat("\u{2013}", $enCount),
            ),
        );
    }

    /**
     * @return array{int, int}
     */
    private static function replacementCounts(int $count): array
    {
        if (0 === $count % 3) {
            return [$count / 3, 0];
        }

        if (0 === $count % 2) {
            return [0, $count / 2];
        }

        if (2 === $count % 3) {
            return [($count - 2) / 3, 1];
        }

        return [(int) (($count - 4) / 3), 2];
    }
}
