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

use Alto\Markdown\Extension\Stats\StatsContext;
use Alto\Markdown\Extension\Stats\StatsMetric;

final class PublicCalloutMetric implements StatsMetric
{
    private bool $used = false;

    public function measure(StatsContext $context): int
    {
        if ($this->used) {
            throw new \LogicException('A custom stats metric instance must not be reused.');
        }

        $this->used = true;
        $count = 0;

        foreach ($context->blocks() as $block) {
            if ('example:callout' === $block->kind) {
                ++$count;
            }
        }

        return $count;
    }
}
