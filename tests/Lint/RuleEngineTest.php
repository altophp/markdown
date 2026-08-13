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

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Lint\BlockRule;
use Alto\Markdown\Lint\DocumentRule;
use Alto\Markdown\Lint\Engine\RuleEngine;
use Alto\Markdown\Lint\InlineRule;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\Rule\NoDeadReferenceDefinitionsRule;
use Alto\Markdown\Lint\Rule\NoEmptyLinksRule;
use Alto\Markdown\Lint\Rule\RequireTitleRule;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Markdown;
use Alto\Markdown\Node\Id\InlineNodeId;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Operation\EditJournal;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Source\SourceDocument;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;
use Alto\Markdown\Traversal\TapeDocumentTraversal;
use PHPUnit\Framework\TestCase;

final class RuleEngineTest extends TestCase
{
    public function testBlockRulesRunInOneTraversalWithoutInlineParsing(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            Paragraph with [link](https://example.com).
            MD);

        Instrumentation::reset();
        $report = new RuleEngine(rules: [new ParagraphBlockRule()])->run($document->model(), new LintConfig());

        self::assertCount(1, $report);
        self::assertSame('test-paragraph', $report->problems[0]->ruleId);
        self::assertSame(1, Instrumentation::$traversals);
        self::assertSame(0, Instrumentation::$inlineParses);
    }

    public function testInlineRulesOptIntoInlineParsing(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            Paragraph with `code` and [link](https://example.com).
            MD);

        Instrumentation::reset();
        $report = new RuleEngine(rules: [new CodeSpanInlineRule()])->run($document->model(), new LintConfig());

        self::assertCount(1, $report);
        self::assertSame('test-code-span', $report->problems[0]->ruleId);
        self::assertSame(1, Instrumentation::$traversals);
        self::assertSame(2, Instrumentation::$inlineParses);
    }

    public function testDocumentRulesDoNotForceTraversal(): void
    {
        $document = Markdown::github()->fromString("# Title\n");

        Instrumentation::reset();
        $report = new RuleEngine(rules: [new DocumentOnlyRule()])->run($document->model(), new LintConfig());

        self::assertCount(1, $report);
        self::assertSame('test-document', $report->problems[0]->ruleId);
        self::assertSame(0, Instrumentation::$traversals);
        self::assertSame(0, Instrumentation::$inlineParses);
    }

    public function testNoDeadAnchorRuleUsesTraversalWithoutLinkHandles(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Intro

            See [missing](#missing-anchor).
            MD);

        Instrumentation::reset();
        $report = $document->lint((new LintConfig())->withRule('no-dead-anchor'));

        self::assertCount(1, $report);
        self::assertSame('no-dead-anchor', $report->problems[0]->ruleId);
        self::assertSame(1, Instrumentation::$traversals);
        self::assertSame(0, Instrumentation::$linkHandles);
    }

    public function testPublicRulesRunWithoutObjectModelMaterialization(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Title

            Paragraph with [internal](#title), [external](https://example.com), and `code`.

            ```php
            echo "ok";
            ```

            ```
            echo "missing language";
            ```

                echo "indented";
            MD);

        Instrumentation::reset();
        $report = $document->lint((new LintConfig())
            ->withRule('single-h1')
            ->withRule('no-skipped-heading-levels')
            ->withRule('require-title')
            ->withRule('no-duplicate-headings')
            ->withRule('no-empty-links')
            ->withRule('no-bare-urls')
            ->withRule('no-dead-reference-definitions')
            ->withRule('require-code-block-language')
            ->withRule('prefer-fenced-code-blocks')
            ->withRule('no-trailing-spaces')
            ->withRule('final-newline')
            ->withRule('no-dead-anchor'));

        self::assertSame(
            ['require-code-block-language', 'prefer-fenced-code-blocks', 'final-newline'],
            self::ruleIds($report->problems),
        );
        self::assertSame(1, Instrumentation::$traversals);
        self::assertSame(0, Instrumentation::$nodeHandles);
        self::assertSame(0, Instrumentation::$headingHandles);
        self::assertSame(0, Instrumentation::$linkHandles);
        self::assertSame(0, Instrumentation::$imageHandles);
        self::assertSame(0, Instrumentation::$codeBlockHandles);
        self::assertSame(0, Instrumentation::$sectionHandles);
    }

    public function testParsedOnlyRulesIgnoreOtherDocumentModels(): void
    {
        $model = new NonParsedDocumentModel();
        $context = new RuleContext(new TapeDocumentTraversal(), new LintConfig());
        $event = new InlineEvent(
            $model,
            new NodeId(0, 0),
            new InlineNodeId(0, 1),
            'link',
            new SourceRange(0, 0),
        );

        self::assertSame([], [...(new RequireTitleRule())->checkDocument($model, $context)]);
        self::assertSame([], [...(new NoDeadReferenceDefinitionsRule())->afterTraversal($model, $context)]);
        self::assertSame([], [...(new NoEmptyLinksRule())->inline($event, $context)]);
    }

    /**
     * @param list<LintProblem> $problems
     *
     * @return list<string>
     */
    private static function ruleIds(array $problems): array
    {
        return array_map(
            static fn(LintProblem $problem): string => $problem->ruleId,
            $problems,
        );
    }
}

final class ParagraphBlockRule implements BlockRule
{
    public function id(): string
    {
        return 'test-paragraph';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        if ('paragraph' !== $event->kind->name) {
            return;
        }

        yield new LintProblem($this->id(), 'paragraph', $event->range);
    }
}

final class CodeSpanInlineRule implements InlineRule
{
    public function id(): string
    {
        return 'test-code-span';
    }

    public function inline(InlineEvent $event, RuleContext $context): iterable
    {
        if ('code-span' !== $event->kind) {
            return;
        }

        yield new LintProblem($this->id(), 'code span', $event->range);
    }
}

final class DocumentOnlyRule implements DocumentRule
{
    public function id(): string
    {
        return 'test-document';
    }

    public function checkDocument(DocumentModel $model, RuleContext $context): iterable
    {
        yield new LintProblem($this->id(), 'document', new SourceRange(0, 0));
    }
}

final class NonParsedDocumentModel implements DocumentModel
{
    public function generation(): int
    {
        throw new \LogicException('Not used by parsed-only rule guards.');
    }

    public function root(): NodeHandle
    {
        throw new \LogicException('Not used by parsed-only rule guards.');
    }

    public function node(NodeId $id): NodeHandle
    {
        throw new \LogicException('Not used by parsed-only rule guards.');
    }

    public function source(): SourceDocument
    {
        throw new \LogicException('Not used by parsed-only rule guards.');
    }

    public function journal(): EditJournal
    {
        throw new \LogicException('Not used by parsed-only rule guards.');
    }
}
