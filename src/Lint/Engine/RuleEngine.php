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

namespace Alto\Markdown\Lint\Engine;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\InvalidLintResultException;
use Alto\Markdown\Extension\Lint\LintContext;
use Alto\Markdown\Extension\Lint\LintDiagnostic;
use Alto\Markdown\Lint\AfterTraversalRule;
use Alto\Markdown\Lint\BlockRule;
use Alto\Markdown\Lint\ConfiguredLintRule;
use Alto\Markdown\Lint\DocumentRule;
use Alto\Markdown\Lint\InlineRule;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\LintReport;
use Alto\Markdown\Lint\Rule;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Traversal\DocumentTraversal;
use Alto\Markdown\Traversal\TapeDocumentTraversal;
use Alto\Markdown\Traversal\TraversalOptions;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class RuleEngine
{
    /**
     * @param iterable<Rule>               $rules
     * @param iterable<ConfiguredLintRule> $customRules
     */
    public function __construct(
        private DocumentTraversal $traversal = new TapeDocumentTraversal(),
        private iterable $rules = [],
        private iterable $customRules = [],
    ) {
    }

    public function run(DocumentModel $model, LintConfig $config): LintReport
    {
        $documentRules = [];
        $blockRules = [];
        $inlineRules = [];
        $afterTraversalRules = [];
        $customRules = array_values([...$this->customRules]);

        foreach ($this->rules as $rule) {
            if ($rule instanceof DocumentRule) {
                $documentRules[] = $rule;
            }

            if ($rule instanceof BlockRule) {
                $blockRules[] = $rule;
            }

            if ($rule instanceof InlineRule) {
                $inlineRules[] = $rule;
            }

            if ($rule instanceof AfterTraversalRule) {
                $afterTraversalRules[] = $rule;
            }
        }

        $context = new RuleContext($this->traversal, $config);
        $problems = [];

        foreach ($documentRules as $rule) {
            array_push($problems, ...$rule->checkDocument($model, $context));
        }

        $visitor = null;

        if ([] !== $blockRules || [] !== $inlineRules || [] !== $customRules) {
            $capturePublicContext = [] !== $customRules;
            $includeInlines = [] !== $inlineRules || $this->customRulesIncludeInlines($customRules);
            $visitor = new RuleEngineVisitor($blockRules, $inlineRules, $context, $capturePublicContext);
            $this->traversal->traverse($model, $visitor, new TraversalOptions(includeInlines: $includeInlines));
            array_push($problems, ...$visitor->problems());
        }

        foreach ($afterTraversalRules as $rule) {
            array_push($problems, ...$rule->afterTraversal($model, $context));
        }

        if ([] !== $customRules && $visitor instanceof RuleEngineVisitor) {
            $publicContext = new LintContext(
                $model->source()->bytes,
                $visitor->blocks(),
                $visitor->inlines(),
            );

            foreach ($customRules as $rule) {
                array_push($problems, ...$this->customProblems($rule, $publicContext, $config));
            }
        }

        return new LintReport($problems);
    }

    /**
     * @param list<ConfiguredLintRule> $rules
     */
    private function customRulesIncludeInlines(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule->definition->includeInlines) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<LintProblem>
     */
    private function customProblems(ConfiguredLintRule $rule, LintContext $context, LintConfig $config): array
    {
        $problems = [];
        $sourceLength = \strlen($context->source());

        foreach ($rule->rule->check($context) as $diagnostic) {
            if (!$diagnostic instanceof LintDiagnostic) {
                throw new InvalidLintResultException(\sprintf('Custom lint rule "%s" must yield %s values.', $rule->id, LintDiagnostic::class));
            }

            if ('' === trim($diagnostic->message)) {
                throw new InvalidLintResultException(\sprintf('Custom lint rule "%s" must provide a non-empty diagnostic message.', $rule->id));
            }

            $this->assertRange($rule->id, 'diagnostic', $diagnostic->range, $sourceLength);
            $operation = null;

            if (null !== $diagnostic->fix) {
                if (!$rule->definition->fixable) {
                    throw new InvalidLintResultException(\sprintf('Custom lint rule "%s" emitted a fix but is not declared fixable.', $rule->id));
                }

                $this->assertRange($rule->id, 'fix', $diagnostic->fix->range, $sourceLength);
                $operation = new SourcePatchOperation(new SourcePatch(
                    $diagnostic->fix->range,
                    $diagnostic->fix->replacement,
                    $diagnostic->fix->range,
                    $diagnostic->fix->description,
                ));
            }

            $problems[] = new LintProblem(
                $rule->id,
                $diagnostic->message,
                $diagnostic->range,
                $operation,
                $config->severityByRule[$rule->id] ?? $rule->definition->defaultSeverity,
            );
        }

        return $problems;
    }

    private function assertRange(string $ruleId, string $kind, SourceRange $range, int $sourceLength): void
    {
        if ($range->startOffset < 0 || $range->endOffset < $range->startOffset || $range->endOffset > $sourceLength) {
            throw new InvalidLintResultException(\sprintf('Custom lint rule "%s" %s range %d..%d must stay within source length %d.', $ruleId, $kind, $range->startOffset, $range->endOffset, $sourceLength));
        }
    }
}
