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

use Alto\Markdown\Extension\Formatter\FormatterContext;
use Alto\Markdown\Extension\Formatter\FormatterEdit;
use Alto\Markdown\Extension\Formatter\FormatterPass;
use Alto\Markdown\Source\SourceRange;

final class PublicCalloutFormatter implements FormatterPass
{
    private bool $used = false;

    public function format(FormatterContext $context): iterable
    {
        if ($this->used) {
            throw new \LogicException('A custom formatter pass instance must not be reused.');
        }

        $this->used = true;

        foreach ($context->blocks() as $block) {
            if ('example:callout' !== $block->kind) {
                continue;
            }

            $source = $context->slice($block->range);

            if (1 !== preg_match('/^(:{3,})([A-Za-z][A-Za-z0-9-]*)/', $source, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $label = $matches[2][0];
            $lowercase = strtolower($label);

            if ($label === $lowercase) {
                continue;
            }

            $start = $block->range->startOffset + $matches[2][1];

            yield FormatterEdit::replace(
                new SourceRange($start, $start + \strlen($label)),
                $lowercase,
                'lowercase callout label',
            );
        }
    }
}
