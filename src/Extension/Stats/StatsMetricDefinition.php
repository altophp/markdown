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

namespace Alto\Markdown\Extension\Stats;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class StatsMetricDefinition
{
    private \Closure $factory;

    /**
     * @param callable(): StatsMetric $factory
     */
    public function __construct(
        public string $name,
        public string $summary,
        callable $factory,
        public bool $includeInlines = false,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new InvalidExtensionException(\sprintf('Stats metric name "%s" must start with a lowercase letter and contain only lowercase letters, digits, and hyphens.', $name));
        }

        if ('' === trim($summary)) {
            throw new InvalidExtensionException('Stats metric summary must not be empty.');
        }

        $this->factory = $factory(...);
    }

    public function create(): StatsMetric
    {
        $metric = ($this->factory)();

        if (!$metric instanceof StatsMetric) {
            throw new InvalidExtensionException(\sprintf('Stats metric factory "%s" must return %s.', $this->name, StatsMetric::class));
        }

        return $metric;
    }
}
