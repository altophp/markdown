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

namespace Alto\Markdown\Tests\Conformance;

/**
 * Runs spec examples through a renderer and tallies exact-match results.
 *
 * Each example is isolated: any Throwable from the renderer counts as a single
 * failure and never aborts the run, so a not-yet-built renderer simply reports
 * every example as failing.
 */
final class ConformanceRunner
{
    /**
     * @param \Closure(SpecExample): HtmlRenderer|null $rendererFor
     */
    public function __construct(
        private readonly HtmlRenderer $renderer,
        private readonly ?\Closure $rendererFor = null,
    ) {
    }

    /**
     * @param iterable<SpecExample> $examples
     */
    public function run(iterable $examples, ?ExampleFilter $filter = null): ConformanceResult
    {
        /** @var array<string, array{passed: int, total: int}> $tally */
        $tally = [];
        $passed = 0;
        $total = 0;

        foreach ($examples as $example) {
            if (null !== $filter && !$filter->matches($example)) {
                continue;
            }

            $section = $example->section;
            if (!isset($tally[$section])) {
                $tally[$section] = ['passed' => 0, 'total' => 0];
            }

            ++$tally[$section]['total'];
            ++$total;

            if ($this->passes($example)) {
                ++$tally[$section]['passed'];
                ++$passed;
            }
        }

        $sections = [];
        foreach ($tally as $section => $counts) {
            $sections[] = new SectionResult($section, $counts['passed'], $counts['total']);
        }

        return new ConformanceResult($sections, $passed, $total);
    }

    private function passes(SpecExample $example): bool
    {
        try {
            $renderer = null === $this->rendererFor ? $this->renderer : ($this->rendererFor)($example);
            $rendered = $renderer->render($example->markdown);
        } catch (\Throwable) {
            return false;
        }

        return $rendered === $example->html;
    }
}
