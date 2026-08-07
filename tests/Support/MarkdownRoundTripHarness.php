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

namespace Alto\Markdown\Tests\Support;

use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Render\RenderOptions;

final readonly class MarkdownRoundTripHarness
{
    public function __construct(
        private MarkdownFactory $markdown,
        private SemanticTreeComparator $comparator = new SemanticTreeComparator(),
    ) {
    }

    /**
     * @param callable(MarkdownDocument, string): string|null $render
     */
    public function compare(string $source, ?callable $render = null, string $name = 'document'): SemanticTreeComparison
    {
        $original = $this->markdown->fromString($source);
        $rendered = null === $render ? $original->toMarkdown(new RenderOptions()) : $render($original, $name);
        $roundTripped = $this->markdown->fromString($rendered);
        $comparison = $this->comparator->compare($original->model(), $roundTripped->model());

        if ($comparison->isEqual()) {
            return $comparison;
        }

        return SemanticTreeComparison::different(\sprintf(
            'Round-trip case "%s" failed: %s',
            $name,
            $comparison->message(),
        ));
    }

    /**
     * @param array<string, string>                           $cases
     * @param callable(MarkdownDocument, string): string|null $render
     */
    public function compareCorpus(array $cases, ?callable $render = null): SemanticTreeComparison
    {
        foreach ($cases as $name => $source) {
            $comparison = $this->compare($source, $render, $name);

            if (!$comparison->isEqual()) {
                return $comparison;
            }
        }

        return SemanticTreeComparison::equal();
    }
}
