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

use Alto\Markdown\Exception\InvalidFormatterResultException;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\InvalidStatsResultException;
use Alto\Markdown\Exception\PatchConflictException;
use Alto\Markdown\Extension\Formatter\FormatterContext;
use Alto\Markdown\Extension\Formatter\FormatterEdit;
use Alto\Markdown\Extension\Formatter\FormatterPass;
use Alto\Markdown\Extension\Formatter\FormatterPassDefinition;
use Alto\Markdown\Extension\FormatterExtensionInterface;
use Alto\Markdown\Extension\Stats\StatsContext;
use Alto\Markdown\Extension\Stats\StatsMetric;
use Alto\Markdown\Extension\Stats\StatsMetricDefinition;
use Alto\Markdown\Extension\StatsExtensionInterface;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Tests\Extension\Fixture\PublicCalloutExtension;
use PHPUnit\Framework\TestCase;

final class PublicFormatterStatsExtensionTest extends TestCase
{
    public function testOneExternalExtensionSupportsTheWholeDocumentWorkflow(): void
    {
        $factory = Markdown::commonmark()->with(new PublicCalloutExtension());
        $source = ":::NOTE\nBody with [link](https://example.com).\n:::\n";
        $document = $factory->fromString($source);

        self::assertCount(1, $document->query()->kind('example:callout')->get());
        self::assertCount(1, $document->lint(
            (new \Alto\Markdown\Lint\LintConfig())->withRule('example:lowercase-label'),
        ));
        self::assertSame(1, $document->stats()->extensionStats['example:callouts']);
        self::assertSame(
            "<aside class=\"callout callout-NOTE\">\n<p>Body with <a href=\"https://example.com\">link</a>.</p>\n</aside>\n",
            $document->toHtml(),
        );

        $fixed = $factory->fromString($source);
        $fixed->fix((new \Alto\Markdown\Lint\LintConfig())->withRule('example:lowercase-label'));
        self::assertSame(":::note\nBody with [link](https://example.com).\n:::\n", $fixed->toMarkdown());

        $formatted = $factory->fromString($source);
        $formatted->format();
        self::assertSame(":::note\nBody with [link](https://example.com).\n:::\n", $formatted->toMarkdown());
        self::assertSame(['lowercase callout label'], array_map(
            static fn($entry): string => $entry->operation->describe(),
            $formatted->model()->journal()->entries(),
        ));
        self::assertSame(
            $formatted->toMarkdown(),
            $factory->fromString($formatted->toMarkdown())->format()->toMarkdown(),
        );
    }

    public function testCoreAndExtensionStatsShareOneTraversal(): void
    {
        $document = Markdown::commonmark()
            ->with(new PublicCalloutExtension())
            ->fromString(":::one\nFirst\n:::\n\n:::two\nSecond\n:::\n");

        Instrumentation::reset();
        $stats = $document->stats();

        self::assertSame(2, $stats->extensionStats['example:callouts']);
        self::assertSame(2, $stats->wordCount);
        self::assertSame(1, Instrumentation::$traversals);
        self::assertGreaterThan(0, Instrumentation::$extensionStatsEvents);
        self::assertSame(2, $document->stats()->extensionStats['example:callouts']);
    }

    public function testContributionOrderingIsDeterministic(): void
    {
        OrderingFormatterPass::$calls = [];
        $document = Markdown::commonmark()
            ->with(new OrderedContributionsExtension())
            ->fromString("Body\n");

        $document->format();
        $stats = $document->stats();

        self::assertSame(['first', 'alpha', 'zeta'], OrderingFormatterPass::$calls);
        self::assertSame(
            ['ordered:alpha', 'ordered:zeta'],
            array_keys($stats->extensionStats),
        );
    }

    public function testInvalidFormatterRangeCannotReachTheJournal(): void
    {
        $document = Markdown::commonmark()
            ->with(new InvalidFormatterExtension())
            ->fromString("Body\n");

        try {
            $document->format();
            self::fail('Expected the invalid custom formatter range to fail.');
        } catch (InvalidFormatterResultException $error) {
            self::assertSame(
                'Custom formatter pass "invalid:range" edit range 0..999 must stay within source length 5.',
                $error->getMessage(),
            );
            self::assertTrue($document->model()->journal()->isEmpty());
            self::assertSame("Body\n", $document->toMarkdown());
        }
    }

    public function testOverlappingFormatterEditsUseTheNormalConflictCheck(): void
    {
        $document = Markdown::commonmark()
            ->with(new OverlappingFormatterExtension())
            ->fromString("Body\n");

        try {
            $document->format();
            self::fail('Expected overlapping custom formatter edits to fail.');
        } catch (PatchConflictException $error) {
            self::assertStringContainsString('Overlapping fix operations', $error->getMessage());
            self::assertTrue($document->model()->journal()->isEmpty());
        }
    }

    public function testInvalidContributionDefinitionsFailWhileBuildingTheFactory(): void
    {
        try {
            Markdown::commonmark()->with(new DuplicateFormatterExtension());
            self::fail('Expected duplicate formatter passes to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame('Duplicate formatter pass "duplicate:same".', $error->getMessage());
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Duplicate stats metric "duplicate:same".');

        Markdown::commonmark()->with(new DuplicateStatsExtension());
    }

    public function testNonFiniteStatsResultFails(): void
    {
        $document = Markdown::commonmark()
            ->with(new NonFiniteStatsExtension())
            ->fromString("Body\n");

        $this->expectException(InvalidStatsResultException::class);
        $this->expectExceptionMessage('Custom stats metric "invalid:not-a-number" must return a finite float.');

        $document->stats();
    }

    public function testInactiveContributionsDoNoExtensionWork(): void
    {
        $document = Markdown::commonmark()->fromString("# Title\n\nBody\n");

        Instrumentation::reset();
        $document->format();
        $document->stats();

        self::assertSame(0, Instrumentation::$extensionFormatterContexts);
        self::assertSame(0, Instrumentation::$extensionStatsEvents);
    }

    public function testPublicFixturesCannotImportMutableInternals(): void
    {
        foreach ([
            __DIR__ . '/Fixture/PublicCalloutFormatter.php',
            __DIR__ . '/Fixture/PublicCalloutMetric.php',
        ] as $path) {
            $source = file_get_contents($path);

            self::assertIsString($source);

            foreach (['DocumentModel', 'ParsedDocumentModel', 'Operation', 'EditJournal', 'NodeId'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source);
            }
        }
    }
}

final readonly class OrderedContributionsExtension implements FormatterExtensionInterface, StatsExtensionInterface
{
    public function name(): string
    {
        return 'ordered';
    }

    public function formatterPasses(): iterable
    {
        yield new FormatterPassDefinition('zeta', 'Third pass.', static fn(): OrderingFormatterPass => new OrderingFormatterPass('zeta'), order: 20);
        yield new FormatterPassDefinition('alpha', 'Second pass.', static fn(): OrderingFormatterPass => new OrderingFormatterPass('alpha'), order: 20);
        yield new FormatterPassDefinition('first', 'First pass.', static fn(): OrderingFormatterPass => new OrderingFormatterPass('first'), order: 10);
    }

    public function statsMetrics(): iterable
    {
        yield new StatsMetricDefinition('zeta', 'Zeta metric.', static fn(): ConstantStatsMetric => new ConstantStatsMetric());
        yield new StatsMetricDefinition('alpha', 'Alpha metric.', static fn(): ConstantStatsMetric => new ConstantStatsMetric());
    }
}

final class OrderingFormatterPass implements FormatterPass
{
    /**
     * @var list<string>
     */
    public static array $calls = [];

    public function __construct(private string $name) {}

    public function format(FormatterContext $context): iterable
    {
        self::$calls[] = $this->name;

        return [];
    }
}

final readonly class ConstantStatsMetric implements StatsMetric
{
    public function measure(StatsContext $context): int
    {
        return 1;
    }
}

final readonly class InvalidFormatterExtension implements FormatterExtensionInterface
{
    public function name(): string
    {
        return 'invalid';
    }

    public function formatterPasses(): iterable
    {
        yield new FormatterPassDefinition('range', 'Emit an invalid range.', static fn(): InvalidRangeFormatterPass => new InvalidRangeFormatterPass());
    }
}

final readonly class InvalidRangeFormatterPass implements FormatterPass
{
    public function format(FormatterContext $context): iterable
    {
        yield FormatterEdit::delete(new SourceRange(0, 999), 'delete invalid range');
    }
}

final readonly class DuplicateFormatterExtension implements FormatterExtensionInterface
{
    public function name(): string
    {
        return 'duplicate';
    }

    public function formatterPasses(): iterable
    {
        yield new FormatterPassDefinition('same', 'First pass.', static fn(): OrderingFormatterPass => new OrderingFormatterPass('first'));
        yield new FormatterPassDefinition('same', 'Second pass.', static fn(): OrderingFormatterPass => new OrderingFormatterPass('second'));
    }
}

final readonly class OverlappingFormatterExtension implements FormatterExtensionInterface
{
    public function name(): string
    {
        return 'overlap';
    }

    public function formatterPasses(): iterable
    {
        yield new FormatterPassDefinition(
            'edits',
            'Emit overlapping edits.',
            static fn(): OverlappingFormatterPass => new OverlappingFormatterPass(),
        );
    }
}

final readonly class OverlappingFormatterPass implements FormatterPass
{
    public function format(FormatterContext $context): iterable
    {
        yield FormatterEdit::replace(new SourceRange(0, 3), 'Foo', 'replace first range');
        yield FormatterEdit::replace(new SourceRange(2, 4), 'ar', 'replace second range');
    }
}

final readonly class DuplicateStatsExtension implements StatsExtensionInterface
{
    public function name(): string
    {
        return 'duplicate';
    }

    public function statsMetrics(): iterable
    {
        yield new StatsMetricDefinition('same', 'First metric.', static fn(): ConstantStatsMetric => new ConstantStatsMetric());
        yield new StatsMetricDefinition('same', 'Second metric.', static fn(): ConstantStatsMetric => new ConstantStatsMetric());
    }
}

final readonly class NonFiniteStatsExtension implements StatsExtensionInterface
{
    public function name(): string
    {
        return 'invalid';
    }

    public function statsMetrics(): iterable
    {
        yield new StatsMetricDefinition('not-a-number', 'Return NaN.', static fn(): NonFiniteStatsMetric => new NonFiniteStatsMetric());
    }
}

final readonly class NonFiniteStatsMetric implements StatsMetric
{
    public function measure(StatsContext $context): float
    {
        return \NAN;
    }
}
