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

namespace Alto\Markdown\Tests\Lint;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\LintRuleCategory;
use Alto\Markdown\Lint\LintRuleOptions;
use Alto\Markdown\Lint\LintSeverity;
use Alto\Markdown\Lint\Options\RequireCodeBlockLanguageOptions;
use Alto\Markdown\Lint\RuleRegistry;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\TestCase;

final class RuleRegistryTest extends TestCase
{
    private const array EXPECTED_IDS = [
        'single-h1',
        'no-skipped-heading-levels',
        'require-title',
        'no-duplicate-headings',
        'require-image-alt',
        'no-empty-links',
        'no-bare-urls',
        'no-dead-reference-definitions',
        'require-code-block-language',
        'code-fence-info-spacing',
        'prefer-fenced-code-blocks',
        'require-closed-code-fence',
        'consistent-table-columns',
        'no-trailing-spaces',
        'final-newline',
        'no-dead-anchor',
    ];

    private const array RECOMMENDED_IDS = [
        'single-h1',
        'no-skipped-heading-levels',
        'require-title',
        'no-duplicate-headings',
        'no-dead-anchor',
    ];

    private const array FIXABLE_IDS = [
        'no-bare-urls',
        'require-code-block-language',
        'code-fence-info-spacing',
        'prefer-fenced-code-blocks',
        'no-trailing-spaces',
        'final-newline',
    ];

    public function testCatalogContainsMetadataForEveryBuiltInRule(): void
    {
        $registry = new RuleRegistry();
        $metadata = $registry->metadata();

        self::assertSame(self::EXPECTED_IDS, $registry->ids());
        self::assertSame(self::EXPECTED_IDS, array_column($metadata, 'id'));
        self::assertCount(16, array_unique(array_column($metadata, 'id')));

        foreach ($metadata as $rule) {
            self::assertNotSame('', $rule->summary);
            self::assertInstanceOf(LintRuleCategory::class, $rule->category);
            self::assertSame(LintSeverity::Error, $rule->defaultSeverity);
            self::assertSame($rule->id, $registry->create($rule->id)?->id());
            self::assertEquals($rule, $registry->metadataFor($rule->id));
        }

        self::assertNull($registry->metadataFor('not-a-rule'));
    }

    public function testCatalogPublishesRuleCategoriesAndTypedOptionSchema(): void
    {
        $registry = new RuleRegistry();
        $language = $registry->metadataFor('require-code-block-language');

        self::assertNotNull($language);
        self::assertSame(LintRuleCategory::Code, $language->category);
        self::assertSame(RequireCodeBlockLanguageOptions::class, $language->optionsClass);
        self::assertNull($registry->metadataFor('final-newline')?->optionsClass);
    }

    public function testCreateReturnsNullForAnUnknownRule(): void
    {
        self::assertNull(new RuleRegistry()->create('not-a-rule'));
    }

    public function testRecommendedSelectionComesFromCatalogMetadata(): void
    {
        $registry = new RuleRegistry();

        self::assertSame(self::RECOMMENDED_IDS, $registry->recommendedIds());
        self::assertSame(self::RECOMMENDED_IDS, LintConfig::recommended()->enabledRules);
    }

    public function testCatalogDeclaresRulesThatCanEmitFixes(): void
    {
        $fixable = array_map(
            static fn ($metadata): string => $metadata->id,
            array_filter(
                new RuleRegistry()->metadata(),
                static fn ($metadata): bool => $metadata->fixable,
            ),
        );

        self::assertSame(self::FIXABLE_IDS, array_values($fixable));
    }

    public function testResolutionPreservesConfiguredOrderAndCreatesFreshInstances(): void
    {
        $registry = new RuleRegistry();
        $config = new LintConfig(['final-newline', 'require-title']);
        $first = $registry->resolve($config);
        $second = $registry->resolve($config);

        self::assertSame(['final-newline', 'require-title'], array_map(
            static fn ($rule): string => $rule->id(),
            $first,
        ));
        self::assertCount(2, $first);
        self::assertNotSame($first[0], $second[0]);
        self::assertNotSame($first[1], $second[1]);
    }

    public function testResolutionRejectsDuplicateEnabledRulesBeforeTraversal(): void
    {
        $linter = new \Alto\Markdown\Lint\Linter(new LintConfig(['require-title', 'require-title']));

        Instrumentation::reset();

        try {
            $linter->lint(Markdown::github()->fromString("Body\n"));
            self::fail('Expected duplicate rule selection to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame('Duplicate lint rule "require-title".', $error->getMessage());
            self::assertSame(0, Instrumentation::$traversals);
        }
    }

    public function testResolutionRejectsUnknownEnabledRuleBeforeTraversal(): void
    {
        $linter = new \Alto\Markdown\Lint\Linter(new LintConfig(['not-a-rule']));

        Instrumentation::reset();

        try {
            $linter->lint(Markdown::github()->fromString("Body\n"));
            self::fail('Expected unknown rule selection to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame('Unknown lint rule "not-a-rule".', $error->getMessage());
            self::assertSame(0, Instrumentation::$traversals);
        }
    }

    public function testResolutionRejectsUnknownSeverityOnlyRuleBeforeTraversal(): void
    {
        $config = new LintConfig(
            enabledRules: ['require-title'],
            severityByRule: ['not-a-rule' => LintSeverity::Warning],
        );
        $linter = new \Alto\Markdown\Lint\Linter($config);

        Instrumentation::reset();

        try {
            $linter->lint(Markdown::github()->fromString("Body\n"));
            self::fail('Expected unknown severity override to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame('Unknown lint rule "not-a-rule" in severity overrides.', $error->getMessage());
            self::assertSame(0, Instrumentation::$traversals);
        }
    }

    public function testResolutionRejectsUnknownOptionOnlyRuleBeforeTraversal(): void
    {
        $config = new LintConfig(
            enabledRules: ['require-title'],
            optionsByRule: ['not-a-rule' => new UnsupportedRuleOptions()],
        );
        $linter = new \Alto\Markdown\Lint\Linter($config);

        Instrumentation::reset();

        try {
            $linter->lint(Markdown::github()->fromString("Body\n"));
            self::fail('Expected unknown option override to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame('Unknown lint rule "not-a-rule" in option overrides.', $error->getMessage());
            self::assertSame(0, Instrumentation::$traversals);
        }
    }

    public function testResolutionRejectsOptionsForRuleWithoutSchemaBeforeTraversal(): void
    {
        $config = new LintConfig(
            enabledRules: ['final-newline'],
            optionsByRule: ['final-newline' => new UnsupportedRuleOptions()],
        );
        $linter = new \Alto\Markdown\Lint\Linter($config);

        Instrumentation::reset();

        try {
            $linter->lint(Markdown::github()->fromString("Body\n"));
            self::fail('Expected unsupported options to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame('Lint rule "final-newline" does not accept options.', $error->getMessage());
            self::assertSame(0, Instrumentation::$traversals);
        }
    }

    public function testResolutionRejectsWrongTypedOptionsBeforeTraversal(): void
    {
        $config = new LintConfig(
            enabledRules: ['require-code-block-language'],
            optionsByRule: ['require-code-block-language' => new UnsupportedRuleOptions()],
        );
        $linter = new \Alto\Markdown\Lint\Linter($config);

        Instrumentation::reset();

        try {
            $linter->lint(Markdown::github()->fromString("```\ncode\n```\n"));
            self::fail('Expected mismatched options to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertStringContainsString('expects options of type '.RequireCodeBlockLanguageOptions::class, $error->getMessage());
            self::assertSame(0, Instrumentation::$traversals);
        }
    }
}

final readonly class UnsupportedRuleOptions implements LintRuleOptions
{
}
