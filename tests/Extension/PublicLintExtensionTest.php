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

namespace Alto\Markdown\Tests\Extension;

use Alto\Markdown\Exception\InvalidLintResultException;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Lint\LintContext;
use Alto\Markdown\Extension\Lint\LintDiagnostic;
use Alto\Markdown\Extension\Lint\LintFix;
use Alto\Markdown\Extension\Lint\LintInline;
use Alto\Markdown\Extension\Lint\LintRule;
use Alto\Markdown\Extension\Lint\LintRuleDefinition;
use Alto\Markdown\Extension\LintExtensionInterface;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\Linter;
use Alto\Markdown\Lint\LintRuleOptions;
use Alto\Markdown\Lint\LintSeverity;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Tests\Extension\Fixture\PublicCalloutExtension;
use PHPUnit\Framework\TestCase;

final class PublicLintExtensionTest extends TestCase
{
    public function testInstalledExtensionLintsAndFixesThroughTheDocumentApi(): void
    {
        $source = ":::NOTE\nBody\n:::\n";
        $factory = Markdown::commonmark()->with(new PublicCalloutExtension());
        $document = $factory->fromString($source);
        $config = (new LintConfig())->withRule('example:lowercase-label');
        $report = $document->lint($config);

        self::assertCount(1, $report);
        self::assertSame('example:lowercase-label', $report->problems[0]->ruleId);
        self::assertSame('Callout label must be lowercase.', $report->problems[0]->message);
        self::assertEquals(new SourceRange(3, 7), $report->problems[0]->range);
        self::assertSame(LintSeverity::Warning, $report->problems[0]->severity);
        self::assertNotNull($report->problems[0]->fix);
        self::assertSame($source, $document->toMarkdown());
        self::assertTrue($document->model()->journal()->isEmpty());

        self::assertSame($document, $document->fix($config));
        self::assertSame(":::note\nBody\n:::\n", $document->toMarkdown());
        self::assertCount(0, $factory->fromString($document->toMarkdown())->lint($config));
    }

    public function testBuiltInAndCustomRulesShareOneTraversalAndFreshInstances(): void
    {
        $factory = Markdown::commonmark()->with(new PublicCalloutExtension());
        $config = (new LintConfig())
            ->withRule('require-title')
            ->withRule('example:lowercase-label');
        $linter = new Linter($config);

        Instrumentation::reset();
        $first = $linter->lint($factory->fromString(":::NOTE\nFirst\n:::\n"));

        self::assertSame(['require-title', 'example:lowercase-label'], array_column($first->problems, 'ruleId'));
        self::assertSame(1, Instrumentation::$traversals);
        self::assertSame(0, Instrumentation::$inlineParses);

        $second = $linter->lint($factory->fromString(":::WARNING\nSecond\n:::\n"));

        self::assertSame(['require-title', 'example:lowercase-label'], array_column($second->problems, 'ruleId'));
    }

    public function testSeverityOverrideAppliesOutsideTheCustomRule(): void
    {
        $document = Markdown::commonmark()
            ->with(new PublicCalloutExtension())
            ->fromString(":::NOTE\nBody\n:::\n");
        $config = (new LintConfig())
            ->withRule('example:lowercase-label')
            ->withSeverity('example:lowercase-label', LintSeverity::Error);

        self::assertSame(LintSeverity::Error, $document->lint($config)->problems[0]->severity);
    }

    public function testFactoryWithoutTheExtensionRejectsTheQualifiedRuleBeforeTraversal(): void
    {
        Instrumentation::reset();

        try {
            Markdown::commonmark()
                ->fromString(":::NOTE\nBody\n:::\n")
                ->lint((new LintConfig())->withRule('example:lowercase-label'));
            self::fail('Expected the unknown custom rule to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame('Unknown lint rule "example:lowercase-label".', $error->getMessage());
            self::assertSame(0, Instrumentation::$traversals);
        }
    }

    public function testDuplicateLocalRuleNamesFailWhenTheFactoryIsBuilt(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Duplicate lint rule "duplicate:same".');

        Markdown::commonmark()->with(new DuplicateLintExtension());
    }

    public function testInvalidCustomResultCannotReachTheJournal(): void
    {
        $document = Markdown::commonmark()
            ->with(new InvalidResultLintExtension())
            ->fromString("Body\n");

        try {
            $document->fix((new LintConfig())->withRule('invalid:range'));
            self::fail('Expected the invalid custom range to fail.');
        } catch (InvalidLintResultException $error) {
            self::assertSame(
                'Custom lint rule "invalid:range" diagnostic range 0..999 must stay within source length 5.',
                $error->getMessage(),
            );
            self::assertTrue($document->model()->journal()->isEmpty());
            self::assertSame("Body\n", $document->toMarkdown());
        }
    }

    public function testRuleCannotEmitAFixUnlessItsDefinitionAllowsIt(): void
    {
        $document = Markdown::commonmark()
            ->with(new UndeclaredFixLintExtension())
            ->fromString("Body\n");

        $this->expectException(InvalidLintResultException::class);
        $this->expectExceptionMessage('Custom lint rule "invalid:undeclared-fix" emitted a fix but is not declared fixable.');

        $document->lint((new LintConfig())->withRule('invalid:undeclared-fix'));
    }

    public function testInvalidRuleDefinitionFailsWhileBuildingTheFactory(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Lint rule name "Not Valid"');

        Markdown::commonmark()->with(new InvalidDefinitionLintExtension());
    }

    public function testPublicRuleFixtureCannotImportMutableInternals(): void
    {
        $path = __DIR__.'/Fixture/PublicCalloutLabelRule.php';
        $source = file_get_contents($path);

        self::assertIsString($source);

        foreach (['DocumentModel', 'ParsedDocumentModel', 'Operation', 'EditJournal', 'NodeId'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testLintContextAndFixFactoriesExposeValidatedValues(): void
    {
        $inline = new LintInline('text', new SourceRange(1, 3));
        $context = new LintContext('body', [], [$inline]);
        $insert = LintFix::insert(2, 'x', 'insert x');

        self::assertSame('body', $context->source());
        self::assertSame([], $context->blocks());
        self::assertSame([$inline], $context->inlines());
        self::assertSame('od', $context->slice($inline->range));
        self::assertEquals(new SourceRange(2, 2), $insert->range);
        self::assertSame('x', $insert->replacement);
    }

    public function testLintContextRejectsAnOutOfBoundsSlice(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Lint source range -1..2 must stay within source length 4.');

        new LintContext('body', [], [])->slice(new SourceRange(-1, 2));
    }

    public function testLintFixRejectsAnEmptyDescription(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Lint fix description must not be empty.');

        LintFix::delete(new SourceRange(0, 1), ' ');
    }

    public function testLintRuleDefinitionRejectsAnEmptySummary(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Lint rule summary must not be empty.');

        new LintRuleDefinition('valid', ' ', static fn (): EmptyLintRule => new EmptyLintRule());
    }

    public function testLintRuleDefinitionRejectsAnInvalidFactoryResult(): void
    {
        $definition = new \ReflectionClass(LintRuleDefinition::class)->newInstance(
            'valid',
            'Valid rule.',
            static fn (): object => new \stdClass(),
        );
        self::assertInstanceOf(LintRuleDefinition::class, $definition);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Lint rule factory "valid" must return');

        $definition->create();
    }

    public function testInlineAwareCustomRuleReceivesInlineSnapshots(): void
    {
        $rule = $this->createMock(LintRule::class);
        $rule->expects(self::once())
            ->method('check')
            ->with(self::callback(
                static fn (LintContext $context): bool => [] !== $context->inlines(),
            ))
            ->willReturn([]);
        $document = Markdown::commonmark()
            ->with(new InjectedLintExtension('inline-aware', $rule, includeInlines: true))
            ->fromString("Text with *emphasis*.\n");

        self::assertTrue($document->lint(
            (new LintConfig())->withRule('inline-aware:rule'),
        )->isClean());
    }

    public function testCustomRuleRejectsInvalidYieldedValues(): void
    {
        $rule = self::createStub(LintRule::class);
        $rule->method('check')->willReturn([new \stdClass()]);
        $document = Markdown::commonmark()
            ->with(new InjectedLintExtension('invalid-yield', $rule))
            ->fromString("Body\n");

        $this->expectException(InvalidLintResultException::class);
        $this->expectExceptionMessage('must yield');

        $document->lint((new LintConfig())->withRule('invalid-yield:rule'));
    }

    public function testCustomRuleRejectsTypedOptionOverrides(): void
    {
        $rule = self::createStub(LintRule::class);
        $document = Markdown::commonmark()
            ->with(new InjectedLintExtension('custom-options', $rule))
            ->fromString("Body\n");
        $config = (new LintConfig())
            ->withRule('custom-options:rule')
            ->withOptions('custom-options:rule', new CustomRuleOptions());

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Custom lint rule "custom-options:rule" does not declare typed options.');

        $document->lint($config);
    }

    public function testCustomRuleRejectsAnEmptyDiagnosticMessage(): void
    {
        $rule = self::createStub(LintRule::class);
        $rule->method('check')->willReturn([
            new LintDiagnostic(' ', new SourceRange(0, 0)),
        ]);
        $document = Markdown::commonmark()
            ->with(new InjectedLintExtension('empty-message', $rule))
            ->fromString("Body\n");

        $this->expectException(InvalidLintResultException::class);
        $this->expectExceptionMessage('must provide a non-empty diagnostic message');

        $document->lint((new LintConfig())->withRule('empty-message:rule'));
    }
}

final readonly class DuplicateLintExtension implements LintExtensionInterface
{
    public function name(): string
    {
        return 'duplicate';
    }

    public function lintRules(): iterable
    {
        yield new LintRuleDefinition('same', 'First rule.', static fn (): EmptyLintRule => new EmptyLintRule());
        yield new LintRuleDefinition('same', 'Second rule.', static fn (): EmptyLintRule => new EmptyLintRule());
    }
}

final readonly class CustomRuleOptions implements LintRuleOptions
{
}

final readonly class InvalidResultLintExtension implements LintExtensionInterface
{
    public function name(): string
    {
        return 'invalid';
    }

    public function lintRules(): iterable
    {
        yield new LintRuleDefinition('range', 'Return an invalid range.', static fn (): InvalidRangeLintRule => new InvalidRangeLintRule());
    }
}

final readonly class UndeclaredFixLintExtension implements LintExtensionInterface
{
    public function name(): string
    {
        return 'invalid';
    }

    public function lintRules(): iterable
    {
        yield new LintRuleDefinition(
            'undeclared-fix',
            'Emit a fix without declaring it.',
            static fn (): UndeclaredFixLintRule => new UndeclaredFixLintRule(),
        );
    }
}

final readonly class InvalidDefinitionLintExtension implements LintExtensionInterface
{
    public function name(): string
    {
        return 'invalid-definition';
    }

    public function lintRules(): iterable
    {
        yield new LintRuleDefinition('Not Valid', 'Invalid rule name.', static fn (): EmptyLintRule => new EmptyLintRule());
    }
}

final readonly class EmptyLintRule implements LintRule
{
    public function check(LintContext $context): iterable
    {
        return [];
    }
}

final readonly class InvalidRangeLintRule implements LintRule
{
    public function check(LintContext $context): iterable
    {
        yield new LintDiagnostic(
            'Invalid range.',
            new SourceRange(0, 999),
            LintFix::delete(new SourceRange(0, 999), 'delete invalid range'),
        );
    }
}

final readonly class UndeclaredFixLintRule implements LintRule
{
    public function check(LintContext $context): iterable
    {
        $range = new SourceRange(0, 4);

        yield new LintDiagnostic(
            'Undeclared fix.',
            $range,
            LintFix::replace($range, 'body', 'lowercase body'),
        );
    }
}

final readonly class InjectedLintExtension implements LintExtensionInterface
{
    public function __construct(
        private string $extensionName,
        private LintRule $rule,
        private bool $includeInlines = false,
    ) {
    }

    public function name(): string
    {
        return $this->extensionName;
    }

    public function lintRules(): iterable
    {
        yield new LintRuleDefinition(
            'rule',
            'Injected test rule.',
            fn (): LintRule => $this->rule,
            includeInlines: $this->includeInlines,
        );
    }
}
