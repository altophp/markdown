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

use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockContinueResult;
use Alto\Markdown\Extension\Block\BlockParser;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockStartResult;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Parser\Block\RootOnlyBlockParser;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class IncludeParser implements BlockParser, RootOnlyBlockParser
{
    public function __construct(private ?IncludeExpander $expander = null)
    {
    }

    public function triggerBytes(): string
    {
        return '@';
    }

    public function tryStart(BlockStartContext $context): ?BlockStartResult
    {
        $reference = IncludeSyntax::reference($context);
        if (null === $reference) {
            return null;
        }

        $state = null === $this->expander
            ? new BlockState(['reference' => $reference])
            : new BlockState(['content' => $this->expander->expand($reference)]);

        return new BlockStartResult(
            startOffset: $context->firstNonSpaceOffset(),
            advanceOffset: $context->lineContentEndOffset(),
            state: $state,
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        unset($context);

        return BlockContinueResult::notMatched();
    }
}
