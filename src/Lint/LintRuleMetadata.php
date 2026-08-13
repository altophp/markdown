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

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LintRuleMetadata
{
    /**
     * @param class-string<LintRuleOptions>|null $optionsClass
     */
    public function __construct(
        public string $id,
        public string $summary,
        public LintSeverity $defaultSeverity,
        public bool $recommended,
        public bool $fixable,
        public LintRuleCategory $category = LintRuleCategory::Style,
        public ?string $optionsClass = null,
    ) {}
}
