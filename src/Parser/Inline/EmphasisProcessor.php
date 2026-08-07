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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Parser\Instrumentation;

/**
 * The spec's process-emphasis algorithm (CommonMark 0.31.2, appendix),
 * implemented with the openers-bottom bounds from the appendix: walk closers
 * bottom-up, scan back for a matching opener under the rule of three, wrap
 * the nodes between them, and remember failed search bounds by delimiter
 * class so later closers never rescan a known-empty prefix.
 *
 * Matching is representation-free: the wrapper applies each matched pair
 * to its own output (tape nodes or HTML segments), so both parsing lanes
 * share this loop and its linearity guarantees.
 *
 * Works on a sub-range of the delimiter list ($stackBottom) so link text
 * processing (T3.8) can resolve emphasis inside brackets independently.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class EmphasisProcessor
{
    /**
     * @param list<Delimiter> $delimiters in document order
     */
    public function process(EmphasisWrapper $wrapper, array $delimiters, int $stackBottom = 0): void
    {
        $count = \count($delimiters);
        $closerIndex = $stackBottom;

        /** @var array<string, int> $openersBottom */
        $openersBottom = [];

        while ($closerIndex < $count) {
            $closer = $delimiters[$closerIndex];

            if (!$closer->active || !$closer->canClose || 0 === $closer->length) {
                ++$closerIndex;

                continue;
            }

            $bottomKey = $this->openersBottomKey($closer);
            $bottom = $openersBottom[$bottomKey] ?? $stackBottom - 1;
            $openerIndex = $closerIndex - 1;

            while ($openerIndex > $bottom) {
                if (Instrumentation::$trackInlineComplexity) {
                    ++Instrumentation::$delimiterSearchSteps;
                }

                $opener = $delimiters[$openerIndex];

                if ($opener->active && $opener->canOpen && $opener->length > 0 && $opener->char === $closer->char && !$this->delimiterRuleForbids($opener, $closer)) {
                    break;
                }

                --$openerIndex;
            }

            if ($openerIndex <= $bottom) {
                $openersBottom[$bottomKey] = $closer->canOpen ? $closerIndex - 1 : $closerIndex;

                if (!$closer->canOpen) {
                    $closer->active = false;
                }

                ++$closerIndex;

                continue;
            }

            $opener = $delimiters[$openerIndex];
            $use = '~' === $closer->char ? 2 : ($opener->length >= 2 && $closer->length >= 2 ? 2 : 1);

            $wrapper->wrap($opener, $closer, $use);

            // Delimiters strictly between the pair can never match across
            // the new node: deactivate them.
            for ($i = $openerIndex + 1; $i < $closerIndex; ++$i) {
                $delimiters[$i]->active = false;
            }

            if (0 === $closer->length) {
                ++$closerIndex;
            }
        }
    }

    private function openersBottomKey(Delimiter $closer): string
    {
        if ('~' === $closer->char) {
            return '~:'.($closer->canOpen ? '1' : '0').':'.($closer->length >= 2 ? '2' : '1');
        }

        return $closer->char.':'.($closer->canOpen ? '1' : '0').':'.($closer->length % 3);
    }

    private function delimiterRuleForbids(Delimiter $opener, Delimiter $closer): bool
    {
        if ('~' === $closer->char) {
            return $opener->length < 2 || $closer->length < 2;
        }

        if (!$closer->canOpen && !$opener->canClose) {
            return false;
        }

        return 0 === ($opener->length + $closer->length) % 3
            && !(0 === $opener->length % 3 && 0 === $closer->length % 3);
    }
}
