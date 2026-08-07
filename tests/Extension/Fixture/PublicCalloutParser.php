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

use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockContinueResult;
use Alto\Markdown\Extension\Block\BlockParser;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockStartResult;
use Alto\Markdown\Extension\Block\BlockState;

final readonly class PublicCalloutParser implements BlockParser
{
    public function triggerBytes(): string
    {
        return ':';
    }

    public function tryStart(BlockStartContext $context): ?BlockStartResult
    {
        if ($context->paragraphOpen() || $context->indentColumns() > 3) {
            return null;
        }

        $first = $context->firstNonSpaceOffset();
        $line = $context->slice($first, $context->lineContentEndOffset());

        if (1 !== preg_match('/^(:{3,})([A-Za-z][A-Za-z0-9-]*)[ \t]*$/D', $line, $matches)) {
            return null;
        }

        return new BlockStartResult(
            $first,
            $context->lineContentEndOffset(),
            container: true,
            state: new BlockState([
                'fence' => \strlen($matches[1]),
                'label' => $matches[2],
            ]),
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        if ($context->indentColumns() <= 3) {
            $first = $context->firstNonSpaceOffset();
            $line = $context->slice($first, $context->lineContentEndOffset());

            if (1 === preg_match('/^(:{3,})[ \t]*$/D', $line, $matches)
                && \strlen($matches[1]) >= $context->state()->int('fence')
            ) {
                return BlockContinueResult::closed($context->lineContentEndOffset());
            }
        }

        return BlockContinueResult::matched();
    }
}
