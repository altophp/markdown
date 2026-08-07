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

namespace Alto\Markdown\Lint;

use Alto\Markdown\Traversal\InlineEvent;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface InlineRule extends Rule
{
    /**
     * @return iterable<LintProblem>
     */
    public function inline(InlineEvent $event, RuleContext $context): iterable;
}
