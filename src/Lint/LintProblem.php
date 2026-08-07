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

use Alto\Markdown\Operation\Operation;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LintProblem
{
    public function __construct(
        public string $ruleId,
        public string $message,
        public SourceRange $range,
        public ?Operation $fix = null,
        public LintSeverity $severity = LintSeverity::Error,
    ) {
    }
}
