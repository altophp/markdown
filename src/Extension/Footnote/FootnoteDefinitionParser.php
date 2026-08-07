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

namespace Alto\Markdown\Extension\Footnote;

use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockContinueResult;
use Alto\Markdown\Extension\Block\BlockParser;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockStartResult;
use Alto\Markdown\Extension\Block\BlockState;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FootnoteDefinitionParser implements BlockParser
{
    public function triggerBytes(): string
    {
        return '[';
    }

    public function tryStart(BlockStartContext $context): ?BlockStartResult
    {
        if ($context->paragraphOpen() || $context->indentColumns() > 3) {
            return null;
        }

        $first = $context->firstNonSpaceOffset();
        $line = $context->slice($first, $context->lineContentEndOffset());

        if (1 !== preg_match('/^\[\^([^\s\^\]]{1,128})\]:(?:[ \t]+|$)/D', $line, $matches)) {
            return null;
        }

        return new BlockStartResult(
            $first,
            $first + \strlen($matches[0]),
            container: true,
            state: new BlockState(['label' => $matches[1]]),
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        $first = $context->firstNonSpaceOffset();

        if ($first >= $context->lineContentEndOffset()) {
            return BlockContinueResult::matched($context->lineContentEndOffset());
        }

        return $context->indentColumns() >= 4
            ? BlockContinueResult::matched($first)
            : BlockContinueResult::notMatched();
    }
}
