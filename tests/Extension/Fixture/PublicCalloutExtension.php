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

namespace Alto\Markdown\Tests\Extension\Fixture;

use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\BlockExtensionInterface;
use Alto\Markdown\Extension\Formatter\FormatterPassDefinition;
use Alto\Markdown\Extension\FormatterExtensionInterface;
use Alto\Markdown\Extension\Lint\LintRuleDefinition;
use Alto\Markdown\Extension\LintExtensionInterface;
use Alto\Markdown\Extension\Stats\StatsMetricDefinition;
use Alto\Markdown\Extension\StatsExtensionInterface;
use Alto\Markdown\Lint\LintSeverity;

final readonly class PublicCalloutExtension implements BlockExtensionInterface, FormatterExtensionInterface, LintExtensionInterface, StatsExtensionInterface
{
    public function name(): string
    {
        return 'example';
    }

    /**
     * @return iterable<BlockDefinition>
     */
    public function blocks(): iterable
    {
        $output = new PublicCalloutOutput();

        yield new BlockDefinition(
            kind: 'callout',
            parser: new PublicCalloutParser(),
            html: $output,
            markdown: $output,
        );
    }

    public function lintRules(): iterable
    {
        yield new LintRuleDefinition(
            name: 'lowercase-label',
            summary: 'Require lowercase callout labels.',
            factory: static fn (): PublicCalloutLabelRule => new PublicCalloutLabelRule(),
            defaultSeverity: LintSeverity::Warning,
            fixable: true,
        );
    }

    public function formatterPasses(): iterable
    {
        yield new FormatterPassDefinition(
            name: 'lowercase-label',
            summary: 'Normalize callout labels to lowercase.',
            factory: static fn (): PublicCalloutFormatter => new PublicCalloutFormatter(),
        );
    }

    public function statsMetrics(): iterable
    {
        yield new StatsMetricDefinition(
            name: 'callouts',
            summary: 'Count callout blocks.',
            factory: static fn (): PublicCalloutMetric => new PublicCalloutMetric(),
        );
    }
}
