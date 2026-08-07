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
final readonly class LintConfig
{
    /**
     * @param list<string>                   $enabledRules
     * @param array<string, LintSeverity>    $severityByRule
     * @param array<string, LintRuleOptions> $optionsByRule
     */
    public function __construct(
        public array $enabledRules = [],
        public array $severityByRule = [],
        public array $optionsByRule = [],
    ) {
    }

    public static function recommended(): self
    {
        return new self(new RuleRegistry()->recommendedIds());
    }

    public function withRule(string $ruleId): self
    {
        if (\in_array($ruleId, $this->enabledRules, true)) {
            return $this;
        }

        return new self([...$this->enabledRules, $ruleId], $this->severityByRule, $this->optionsByRule);
    }

    public function withoutRule(string $ruleId): self
    {
        $rules = array_values(array_filter(
            $this->enabledRules,
            static fn (string $enabled): bool => $enabled !== $ruleId,
        ));
        $severities = $this->severityByRule;
        unset($severities[$ruleId]);
        $options = $this->optionsByRule;
        unset($options[$ruleId]);

        return new self($rules, $severities, $options);
    }

    public function withSeverity(string $ruleId, LintSeverity $severity): self
    {
        return new self(
            $this->enabledRules,
            [...$this->severityByRule, $ruleId => $severity],
            $this->optionsByRule,
        );
    }

    public function withOptions(string $ruleId, LintRuleOptions $options): self
    {
        if (($this->optionsByRule[$ruleId] ?? null) === $options) {
            return $this;
        }

        return new self(
            $this->enabledRules,
            $this->severityByRule,
            [...$this->optionsByRule, $ruleId => $options],
        );
    }

    public function optionsFor(string $ruleId): ?LintRuleOptions
    {
        return $this->optionsByRule[$ruleId] ?? null;
    }

    public function severityFor(string $ruleId): LintSeverity
    {
        return $this->severityByRule[$ruleId] ?? LintSeverity::Error;
    }
}
