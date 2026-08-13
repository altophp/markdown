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

namespace Alto\Markdown\Extension\Block;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class BlockContinueResult
{
    /**
     * A null advance offset leaves the line available for nested block
     * parsing. A non-null offset addresses an original input byte.
     */
    private function __construct(
        public BlockContinueAction $action,
        public ?int $advanceOffset,
        public ?BlockState $state,
    ) {}

    public static function matched(?int $advanceOffset = null, ?BlockState $state = null): self
    {
        return new self(BlockContinueAction::Matched, $advanceOffset, $state);
    }

    public static function notMatched(): self
    {
        return new self(BlockContinueAction::NotMatched, null, null);
    }

    public static function closed(int $advanceOffset): self
    {
        return new self(BlockContinueAction::Closed, $advanceOffset, null);
    }
}
