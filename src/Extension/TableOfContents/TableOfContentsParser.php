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

namespace Alto\Markdown\Extension\TableOfContents;

use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockContinueResult;
use Alto\Markdown\Extension\Block\BlockParser;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockStartResult;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Extension\Resource\ResourceOptionTokenizer;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TableOfContentsParser implements BlockParser
{
    private const int MAX_DIRECTIVE_BYTES = 512;

    /**
     * @var non-empty-array<string, true>
     */
    private const array OPTION_NAMES = [
        'min' => true,
        'max' => true,
        'ordered' => true,
    ];

    public function __construct(private TableOfContentsPolicy $policy)
    {
    }

    public function triggerBytes(): string
    {
        return $this->policy->marker[0];
    }

    public function tryStart(BlockStartContext $context): ?BlockStartResult
    {
        if ($context->paragraphOpen() || 0 !== $context->indentColumns()) {
            return null;
        }

        $first = $context->firstNonSpaceOffset();
        $line = $context->slice($first, $context->lineContentEndOffset());
        if (\strlen($line) > self::MAX_DIRECTIVE_BYTES) {
            return null;
        }

        $trimmed = rtrim($line, " \t");
        if (!str_starts_with($trimmed, $this->policy->marker)) {
            return null;
        }

        $rest = substr($trimmed, \strlen($this->policy->marker));
        if ('' === $rest) {
            $options = [];
        } else {
            if (1 !== preg_match('/^[ \t]+\{([^{}]*)\}$/D', $rest, $match)) {
                return null;
            }

            $options = ResourceOptionTokenizer::tokenize($match[1], self::OPTION_NAMES);
            if (null === $options) {
                return null;
            }
        }

        $min = $this->policy->minLevel;
        $max = $this->policy->maxLevel;
        $ordered = TableOfContentsStyle::Ordered === $this->policy->style;

        foreach ($options as $name => $option) {
            if ($option->doubleQuoted) {
                return null;
            }

            if ('ordered' === $name) {
                if ('true' !== $option->value && 'false' !== $option->value) {
                    return null;
                }

                $ordered = 'true' === $option->value;

                continue;
            }

            if (1 !== preg_match('/^[1-6]$/D', $option->value)) {
                return null;
            }

            if ('min' === $name) {
                $min = (int) $option->value;
            } else {
                $max = (int) $option->value;
            }
        }

        if ($min > $max) {
            return null;
        }

        return new BlockStartResult(
            startOffset: $first,
            advanceOffset: $context->lineContentEndOffset(),
            state: new BlockState([
                'min' => $min,
                'max' => $max,
                'ordered' => $ordered,
            ]),
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        unset($context);

        return BlockContinueResult::notMatched();
    }
}
