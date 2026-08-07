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

use Alto\Markdown\Extension\Lint\LintRule;
use Alto\Markdown\Extension\Lint\LintRuleDefinition;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ConfiguredLintRule
{
    public function __construct(
        public string $id,
        public LintRuleDefinition $definition,
        public LintRule $rule,
    ) {
    }
}
