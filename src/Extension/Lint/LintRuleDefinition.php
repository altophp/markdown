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

namespace Alto\Markdown\Extension\Lint;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Lint\LintSeverity;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LintRuleDefinition
{
    private \Closure $factory;

    /**
     * @param callable(): LintRule $factory
     */
    public function __construct(
        public string $name,
        public string $summary,
        callable $factory,
        public LintSeverity $defaultSeverity = LintSeverity::Error,
        public bool $fixable = false,
        public bool $includeInlines = false,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new InvalidExtensionException(\sprintf('Lint rule name "%s" must start with a lowercase letter and contain only lowercase letters, digits, and hyphens.', $name));
        }

        if ('' === trim($summary)) {
            throw new InvalidExtensionException('Lint rule summary must not be empty.');
        }

        $this->factory = $factory(...);
    }

    public function create(): LintRule
    {
        $rule = ($this->factory)();

        if (!$rule instanceof LintRule) {
            throw new InvalidExtensionException(\sprintf('Lint rule factory "%s" must return %s.', $this->name, LintRule::class));
        }

        return $rule;
    }
}
