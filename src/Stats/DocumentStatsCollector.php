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

namespace Alto\Markdown\Stats;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\InvalidStatsResultException;
use Alto\Markdown\Extension\Stats\StatsContext;
use Alto\Markdown\Extension\Stats\StatsMetricDefinition;
use Alto\Markdown\Stats\Traversal\ExtensionStatsVisitor;
use Alto\Markdown\Stats\Traversal\StatsVisitor;
use Alto\Markdown\Traversal\DocumentTraversal;
use Alto\Markdown\Traversal\TapeDocumentTraversal;
use Alto\Markdown\Traversal\TraversalOptions;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DocumentStatsCollector
{
    public function __construct(
        private DocumentTraversal $traversal = new TapeDocumentTraversal(),
    ) {}

    public function collect(DocumentModel $model): DocumentStats
    {
        $core = new StatsVisitor();
        $definitions = $model instanceof ParsedDocumentModel
            ? $model->compiledProfile()->statsMetrics
            : [];
        $visitor = [] === $definitions
            ? $core
            : new ExtensionStatsVisitor($core, $this->includeExtensionInlines($definitions));
        $this->traversal->traverse($model, $visitor, new TraversalOptions(includeInlines: true));

        if (!$visitor instanceof ExtensionStatsVisitor) {
            return $core->stats();
        }

        return $core->stats($this->extensionStats(
            $definitions,
            $visitor->context($model->source()->bytes),
        ));
    }

    /**
     * @param array<string, StatsMetricDefinition> $definitions
     *
     * @return array<string, int|float|string|bool|null>
     */
    private function extensionStats(array $definitions, StatsContext $context): array
    {
        $stats = [];

        foreach ($definitions as $id => $definition) {
            $value = $definition->create()->measure($context);

            if (\is_float($value) && !is_finite($value)) {
                throw new InvalidStatsResultException(\sprintf('Custom stats metric "%s" must return a finite float.', $id));
            }

            $stats[$id] = $value;
        }

        return $stats;
    }

    /**
     * @param array<string, StatsMetricDefinition> $definitions
     */
    private function includeExtensionInlines(array $definitions): bool
    {
        foreach ($definitions as $definition) {
            if ($definition->includeInlines) {
                return true;
            }
        }

        return false;
    }
}
