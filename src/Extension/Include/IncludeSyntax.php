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

namespace Alto\Markdown\Extension\Include;

use Alto\Markdown\Extension\Block\BlockStartContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract class IncludeSyntax
{
    private const int MAX_DIRECTIVE_BYTES = 4_096;

    public static function reference(BlockStartContext $context): ?string
    {
        if ($context->paragraphOpen() || 0 !== $context->indentColumns()) {
            return null;
        }

        $first = $context->firstNonSpaceOffset();
        if ($first !== $context->lineStartOffset()) {
            return null;
        }

        $line = $context->slice($first, $context->lineContentEndOffset());
        if (
            \strlen($line) > self::MAX_DIRECTIVE_BYTES
            || !str_starts_with($line, '@include')
            || !isset($line[8])
            || (' ' !== $line[8] && "\t" !== $line[8])
            || 1 !== preg_match('/^@include[ \t]+"([^"\x00-\x1f]+)"[ \t]*$/D', $line, $matches)
        ) {
            return null;
        }

        return $matches[1];
    }
}
