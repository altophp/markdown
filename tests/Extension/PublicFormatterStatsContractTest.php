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

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Formatter\FormatterBlock;
use Alto\Markdown\Extension\Formatter\FormatterContext;
use Alto\Markdown\Extension\Formatter\FormatterEdit;
use Alto\Markdown\Extension\Formatter\FormatterInline;
use Alto\Markdown\Extension\Formatter\FormatterPass;
use Alto\Markdown\Extension\Formatter\FormatterPassDefinition;
use Alto\Markdown\Extension\FormatterExtensionInterface;
use Alto\Markdown\Extension\Stats\StatsBlock;
use Alto\Markdown\Extension\Stats\StatsContext;
use Alto\Markdown\Extension\Stats\StatsInline;
use Alto\Markdown\Extension\Stats\StatsMetric;
use Alto\Markdown\Extension\Stats\StatsMetricDefinition;
use Alto\Markdown\Extension\StatsExtensionInterface;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class PublicFormatterStatsContractTest extends TestCase
{
    public function testPublicContextsExposeOnlyImmutableSourceSnapshots(): void
    {
        $blockRange = new SourceRange(0, 7);
        $inlineRange = new SourceRange(2, 6);
        $formatBlock = new FormatterBlock('paragraph', $blockRange, 1);
        $formatInline = new FormatterInline('text', $inlineRange);
        $style = MarkdownStyle::github();
        $format = new FormatterContext('content', [$formatBlock], [$formatInline], $style);
        $statsBlock = new StatsBlock('paragraph', $blockRange, 1);
        $statsInline = new StatsInline('text', $inlineRange);
        $stats = new StatsContext('content', [$statsBlock], [$statsInline]);

        self::assertSame('content', $format->source());
        self::assertSame([$formatBlock], $format->blocks());
        self::assertSame([$formatInline], $format->inlines());
        self::assertSame($style, $format->style);
        self::assertSame('nten', $format->slice($inlineRange));
        self::assertSame('content', $stats->source());
        self::assertSame([$statsBlock], $stats->blocks());
        self::assertSame([$statsInline], $stats->inlines());
        self::assertSame('nten', $stats->slice($inlineRange));
    }

    public function testPublicContextsRejectOutOfBoundsSlices(): void
    {
        $range = new SourceRange(-1, 2);

        try {
            new FormatterContext('abc', [], [], MarkdownStyle::commonmark())->slice($range);
            self::fail('Expected the formatter context to reject the range.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame(
                'Formatter source range -1..2 must stay within source length 3.',
                $error->getMessage(),
            );
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Stats source range 1..4 must stay within source length 3.');

        new StatsContext('abc', [], [])->slice(new SourceRange(1, 4));
    }

    public function testFormatterEditFactoriesDescribeExactPatches(): void
    {
        $range = new SourceRange(1, 3);
        $replace = FormatterEdit::replace($range, 'x', 'replace bytes');
        $insert = FormatterEdit::insert(2, 'y', 'insert bytes');
        $delete = FormatterEdit::delete($range, 'delete bytes');

        self::assertSame($range, $replace->range);
        self::assertSame('x', $replace->replacement);
        self::assertSame('replace bytes', $replace->description);
        self::assertEquals(new SourceRange(2, 2), $insert->range);
        self::assertSame('y', $insert->replacement);
        self::assertSame('', $delete->replacement);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Formatter edit description must not be empty.');

        FormatterEdit::delete($range, ' ');
    }

    public function testDefinitionsValidateNamesAndSummaries(): void
    {
        try {
            new FormatterPassDefinition(
                'Not Valid',
                'Invalid name.',
                static fn(): EmptyPublicFormatterPass => new EmptyPublicFormatterPass(),
            );
            self::fail('Expected the invalid formatter name to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertStringStartsWith('Formatter pass name "Not Valid"', $error->getMessage());
        }

        try {
            new StatsMetricDefinition(
                'valid',
                ' ',
                static fn(): ConstantPublicStatsMetric => new ConstantPublicStatsMetric(),
            );
            self::fail('Expected the empty stats summary to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame('Stats metric summary must not be empty.', $error->getMessage());
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Stats metric name "Not Valid"');

        new StatsMetricDefinition(
            'Not Valid',
            'Invalid name.',
            static fn(): ConstantPublicStatsMetric => new ConstantPublicStatsMetric(),
        );
    }

    public function testFormatterDefinitionRejectsAnEmptySummary(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Formatter pass summary must not be empty.');

        new FormatterPassDefinition(
            'valid',
            ' ',
            static fn(): EmptyPublicFormatterPass => new EmptyPublicFormatterPass(),
        );
    }

    public function testFormatterDefinitionRejectsAnInvalidFactoryResult(): void
    {
        $definition = new \ReflectionClass(FormatterPassDefinition::class)->newInstance(
            'valid',
            'Valid pass.',
            static fn(): object => new \stdClass(),
        );
        self::assertInstanceOf(FormatterPassDefinition::class, $definition);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Formatter pass factory "valid" must return');

        $definition->create();
    }

    public function testStatsDefinitionRejectsAnInvalidFactoryResult(): void
    {
        $definition = new \ReflectionClass(StatsMetricDefinition::class)->newInstance(
            'valid',
            'Valid metric.',
            static fn(): object => new \stdClass(),
        );
        self::assertInstanceOf(StatsMetricDefinition::class, $definition);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Stats metric factory "valid" must return');

        $definition->create();
    }

    public function testInlineSnapshotsAreOptInAndNoOpEditsStayOutOfTheJournal(): void
    {
        InlineAwarePublicFormatterPass::$inlineCount = 0;
        $document = Markdown::commonmark()
            ->with(new InlineAwarePublicExtension())
            ->fromString("Text with *emphasis*.\n");

        $document->format();
        $stats = $document->stats();

        self::assertGreaterThan(0, InlineAwarePublicFormatterPass::$inlineCount);
        self::assertGreaterThan(0, $stats->extensionStats['inline-aware:inlines']);
        self::assertTrue($document->model()->journal()->isEmpty());
    }

    public function testCompilerRejectsInvalidFormatterAndStatsDefinitions(): void
    {
        $formatter = self::createStub(FormatterExtensionInterface::class);
        $formatter->method('name')->willReturn('invalid-formatter-definition');
        $formatter->method('formatterPasses')->willReturn([new \stdClass()]);

        try {
            Markdown::commonmark()->with($formatter);
            self::fail('Expected the invalid formatter definition to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertStringContainsString('formatterPasses() must yield', $error->getMessage());
        }

        $stats = self::createStub(StatsExtensionInterface::class);
        $stats->method('name')->willReturn('invalid-stats-definition');
        $stats->method('statsMetrics')->willReturn([new \stdClass()]);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('statsMetrics() must yield');

        Markdown::commonmark()->with($stats);
    }
}

final readonly class EmptyPublicFormatterPass implements FormatterPass
{
    public function format(FormatterContext $context): iterable
    {
        return [];
    }
}

final readonly class ConstantPublicStatsMetric implements StatsMetric
{
    public function measure(StatsContext $context): int
    {
        return 1;
    }
}

final readonly class InlineAwarePublicExtension implements FormatterExtensionInterface, StatsExtensionInterface
{
    public function name(): string
    {
        return 'inline-aware';
    }

    public function formatterPasses(): iterable
    {
        yield new FormatterPassDefinition(
            'no-op',
            'Inspect inline snapshots without changing source.',
            static fn(): InlineAwarePublicFormatterPass => new InlineAwarePublicFormatterPass(),
            includeInlines: true,
        );
    }

    public function statsMetrics(): iterable
    {
        yield new StatsMetricDefinition(
            'inlines',
            'Count inline snapshots.',
            static fn(): InlineCountPublicStatsMetric => new InlineCountPublicStatsMetric(),
            includeInlines: true,
        );
    }
}

final class InlineAwarePublicFormatterPass implements FormatterPass
{
    public static int $inlineCount = 0;

    public function format(FormatterContext $context): iterable
    {
        self::$inlineCount = \count($context->inlines());

        yield FormatterEdit::replace(new SourceRange(0, 4), 'Text', 'keep identical text');
    }
}

final readonly class InlineCountPublicStatsMetric implements StatsMetric
{
    public function measure(StatsContext $context): int
    {
        return \count($context->inlines());
    }
}
