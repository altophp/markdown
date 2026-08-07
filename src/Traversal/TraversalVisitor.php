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

namespace Alto\Markdown\Traversal;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface TraversalVisitor
{
    public function enterBlock(BlockEvent $event): void;

    public function leaveBlock(BlockEvent $event): void;

    public function inline(InlineEvent $event): void;
}
