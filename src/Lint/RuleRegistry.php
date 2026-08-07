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

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Lint\LintRuleDefinition;
use Alto\Markdown\Lint\Options\RequireCodeBlockLanguageOptions;
use Alto\Markdown\Lint\Rule\CodeFenceInfoSpacingRule;
use Alto\Markdown\Lint\Rule\ConsistentTableColumnsRule;
use Alto\Markdown\Lint\Rule\FinalNewlineRule;
use Alto\Markdown\Lint\Rule\NoBareUrlsRule;
use Alto\Markdown\Lint\Rule\NoDeadAnchorRule;
use Alto\Markdown\Lint\Rule\NoDeadReferenceDefinitionsRule;
use Alto\Markdown\Lint\Rule\NoDuplicateHeadingsRule;
use Alto\Markdown\Lint\Rule\NoEmptyLinksRule;
use Alto\Markdown\Lint\Rule\NoSkippedHeadingLevelsRule;
use Alto\Markdown\Lint\Rule\NoTrailingSpacesRule;
use Alto\Markdown\Lint\Rule\PreferFencedCodeBlocksRule;
use Alto\Markdown\Lint\Rule\RequireClosedCodeFenceRule;
use Alto\Markdown\Lint\Rule\RequireCodeBlockLanguageRule;
use Alto\Markdown\Lint\Rule\RequireImageAltRule;
use Alto\Markdown\Lint\Rule\RequireTitleRule;
use Alto\Markdown\Lint\Rule\SingleH1Rule;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class RuleRegistry
{
    /**
     * @var array<string, array{
     *     class: class-string<Rule>,
     *     summary: string,
     *     category: LintRuleCategory,
     *     defaultSeverity: LintSeverity,
     *     recommended: bool,
     *     fixable: bool,
     *     optionsClass: class-string<LintRuleOptions>|null
     * }>
     */
    private const array RULES = [
        'single-h1' => [
            'class' => SingleH1Rule::class,
            'summary' => 'Allow only one top-level heading.',
            'category' => LintRuleCategory::Structure,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => true,
            'fixable' => false,
            'optionsClass' => null,
        ],
        'no-skipped-heading-levels' => [
            'class' => NoSkippedHeadingLevelsRule::class,
            'summary' => 'Require heading levels to increase one level at a time.',
            'category' => LintRuleCategory::Structure,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => true,
            'fixable' => false,
            'optionsClass' => null,
        ],
        'require-title' => [
            'class' => RequireTitleRule::class,
            'summary' => 'Require the document to start with a top-level heading.',
            'category' => LintRuleCategory::Structure,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => true,
            'fixable' => false,
            'optionsClass' => null,
        ],
        'no-duplicate-headings' => [
            'class' => NoDuplicateHeadingsRule::class,
            'summary' => 'Disallow headings with duplicate text.',
            'category' => LintRuleCategory::Structure,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => true,
            'fixable' => false,
            'optionsClass' => null,
        ],
        'require-image-alt' => [
            'class' => RequireImageAltRule::class,
            'summary' => 'Require images to have alternative text.',
            'category' => LintRuleCategory::Accessibility,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => false,
            'optionsClass' => null,
        ],
        'no-empty-links' => [
            'class' => NoEmptyLinksRule::class,
            'summary' => 'Disallow links and images with empty destinations.',
            'category' => LintRuleCategory::Links,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => false,
            'optionsClass' => null,
        ],
        'no-bare-urls' => [
            'class' => NoBareUrlsRule::class,
            'summary' => 'Require angle brackets around bare URLs.',
            'category' => LintRuleCategory::Links,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => true,
            'optionsClass' => null,
        ],
        'no-dead-reference-definitions' => [
            'class' => NoDeadReferenceDefinitionsRule::class,
            'summary' => 'Disallow unused link reference definitions.',
            'category' => LintRuleCategory::Links,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => false,
            'optionsClass' => null,
        ],
        'require-code-block-language' => [
            'class' => RequireCodeBlockLanguageRule::class,
            'summary' => 'Require a language on fenced code blocks.',
            'category' => LintRuleCategory::Code,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => true,
            'optionsClass' => RequireCodeBlockLanguageOptions::class,
        ],
        'code-fence-info-spacing' => [
            'class' => CodeFenceInfoSpacingRule::class,
            'summary' => 'Disallow whitespace before a code fence info string.',
            'category' => LintRuleCategory::Code,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => true,
            'optionsClass' => null,
        ],
        'prefer-fenced-code-blocks' => [
            'class' => PreferFencedCodeBlocksRule::class,
            'summary' => 'Prefer fenced code blocks over indented code blocks.',
            'category' => LintRuleCategory::Code,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => true,
            'optionsClass' => null,
        ],
        'require-closed-code-fence' => [
            'class' => RequireClosedCodeFenceRule::class,
            'summary' => 'Require fenced code blocks to have a closing fence.',
            'category' => LintRuleCategory::Code,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => false,
            'optionsClass' => null,
        ],
        'consistent-table-columns' => [
            'class' => ConsistentTableColumnsRule::class,
            'summary' => 'Require table body rows to match the header width.',
            'category' => LintRuleCategory::Structure,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => false,
            'optionsClass' => null,
        ],
        'no-trailing-spaces' => [
            'class' => NoTrailingSpacesRule::class,
            'summary' => 'Disallow trailing spaces except Markdown hard breaks.',
            'category' => LintRuleCategory::Style,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => true,
            'optionsClass' => null,
        ],
        'final-newline' => [
            'class' => FinalNewlineRule::class,
            'summary' => 'Require a final newline.',
            'category' => LintRuleCategory::Style,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => false,
            'fixable' => true,
            'optionsClass' => null,
        ],
        'no-dead-anchor' => [
            'class' => NoDeadAnchorRule::class,
            'summary' => 'Disallow fragment links to missing headings.',
            'category' => LintRuleCategory::Links,
            'defaultSeverity' => LintSeverity::Error,
            'recommended' => true,
            'fixable' => false,
            'optionsClass' => null,
        ],
    ];

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys(self::RULES);
    }

    public function has(string $id): bool
    {
        return isset(self::RULES[$id]);
    }

    /**
     * @return list<LintRuleMetadata>
     */
    public function metadata(): array
    {
        $metadata = [];

        foreach (self::RULES as $id => $definition) {
            $metadata[] = $this->metadataFrom($id, $definition);
        }

        return $metadata;
    }

    public function metadataFor(string $id): ?LintRuleMetadata
    {
        $definition = self::RULES[$id] ?? null;

        return null === $definition ? null : $this->metadataFrom($id, $definition);
    }

    /**
     * @return list<string>
     */
    public function recommendedIds(): array
    {
        $ids = [];

        foreach ($this->metadata() as $metadata) {
            if ($metadata->recommended) {
                $ids[] = $metadata->id;
            }
        }

        return $ids;
    }

    public function create(string $id, ?LintRuleOptions $options = null): ?Rule
    {
        $definition = self::RULES[$id] ?? null;

        if (null === $definition) {
            return null;
        }

        $this->validateOptions($id, $definition['optionsClass'], $options);
        $class = $definition['class'];

        if (is_subclass_of($class, ConfigurableRule::class)) {
            return $class::fromOptions($options);
        }

        return new $class();
    }

    /**
     * @return list<Rule>
     */
    public function resolve(LintConfig $config): array
    {
        return $this->resolveConfigured($config)->builtIns;
    }

    /**
     * @param array<string, LintRuleDefinition> $customDefinitions
     */
    public function resolveConfigured(LintConfig $config, array $customDefinitions = []): ResolvedLintRules
    {
        $seen = [];

        foreach ($config->enabledRules as $id) {
            if (isset($seen[$id])) {
                throw new InvalidMarkdownArgumentException(\sprintf('Duplicate lint rule "%s".', $id));
            }

            if (!$this->has($id) && !isset($customDefinitions[$id])) {
                throw new InvalidMarkdownArgumentException(\sprintf('Unknown lint rule "%s".', $id));
            }

            $seen[$id] = true;
        }

        foreach (array_keys($config->severityByRule) as $id) {
            if (!$this->has($id) && !isset($customDefinitions[$id])) {
                throw new InvalidMarkdownArgumentException(\sprintf('Unknown lint rule "%s" in severity overrides.', $id));
            }
        }

        foreach ($config->optionsByRule as $id => $options) {
            if (!$this->has($id)) {
                if (isset($customDefinitions[$id])) {
                    throw new InvalidMarkdownArgumentException(\sprintf('Custom lint rule "%s" does not declare typed options.', $id));
                }

                throw new InvalidMarkdownArgumentException(\sprintf('Unknown lint rule "%s" in option overrides.', $id));
            }

            $this->validateOptions($id, self::RULES[$id]['optionsClass'], $options);
        }

        $builtIns = [];
        $custom = [];

        foreach ($config->enabledRules as $id) {
            $definition = $customDefinitions[$id] ?? null;

            if ($definition instanceof LintRuleDefinition) {
                $custom[] = new ConfiguredLintRule($id, $definition, $definition->create());

                continue;
            }

            $rule = $this->create($id, $config->optionsFor($id));

            if (!$rule instanceof Rule) {
                throw new \LogicException(\sprintf('Registered lint rule "%s" cannot be created.', $id));
            }

            $builtIns[] = $rule;
        }

        return new ResolvedLintRules($builtIns, $custom);
    }

    /**
     * @param array{
     *     class: class-string<Rule>,
     *     summary: string,
     *     category: LintRuleCategory,
     *     defaultSeverity: LintSeverity,
     *     recommended: bool,
     *     fixable: bool,
     *     optionsClass: class-string<LintRuleOptions>|null
     * } $definition
     */
    private function metadataFrom(string $id, array $definition): LintRuleMetadata
    {
        return new LintRuleMetadata(
            id: $id,
            summary: $definition['summary'],
            category: $definition['category'],
            defaultSeverity: $definition['defaultSeverity'],
            recommended: $definition['recommended'],
            fixable: $definition['fixable'],
            optionsClass: $definition['optionsClass'],
        );
    }

    /**
     * @param class-string<LintRuleOptions>|null $expected
     */
    private function validateOptions(string $id, ?string $expected, ?LintRuleOptions $options): void
    {
        if (null === $options) {
            return;
        }

        if (null === $expected) {
            throw new InvalidMarkdownArgumentException(\sprintf('Lint rule "%s" does not accept options.', $id));
        }

        if ($options::class !== $expected) {
            throw new InvalidMarkdownArgumentException(\sprintf('Lint rule "%s" expects options of type %s, %s given.', $id, $expected, $options::class));
        }
    }
}
