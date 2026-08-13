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

use Alto\Markdown\Traversal\DocumentTraversal;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class RuleContext
{
    public function __construct(
        public DocumentTraversal $traversal,
        public LintConfig $config,
    ) {}
}
