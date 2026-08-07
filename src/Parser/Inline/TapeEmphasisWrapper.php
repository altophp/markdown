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
use Alto\Markdown\Parser\ParseTape;

/**
 * Tape-side emphasis application: wraps the sibling chain strictly
 * between the delimiter nodes in a new EMPHASIS, STRONG, or
 * STRIKETHROUGH node and consumes $use characters from the inner end of
 * the opener run and the start of the closer run. One wrapper serves a
 * whole block: every delimiter node hangs off the same root.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TapeEmphasisWrapper implements EmphasisWrapper
{
    public function __construct(
        private ParseTape $tape,
        private int $root,
        private InlineState $state,
    ) {
    }

    public function wrap(Delimiter $opener, Delimiter $closer, int $use): void
    {
        $tape = $this->tape;
        $kind = '~' === $opener->char ? InlineKind::STRIKETHROUGH : (2 === $use ? InlineKind::STRONG : InlineKind::EMPHASIS);
        $openerNode = $opener->node;
        $closerNode = $closer->node;
        $openerEnd = $tape->endOffset($openerNode);
        $closerStart = $tape->startOffset($closerNode);
        $afterCloser = $tape->nextSiblingOrdinal($closerNode);
        $firstInner = $tape->nextSiblingOrdinal($openerNode);
        $lastInner = ParseTape::NONE;
        $node = $firstInner;

        while (ParseTape::NONE !== $node && $node !== $closerNode) {
            if (Instrumentation::$trackInlineComplexity) {
                ++Instrumentation::$inlineSiblingWalkSteps;
            }

            $lastInner = $node;
            $node = $tape->nextSiblingOrdinal($node);
        }

        // Consume from the opener's end: its node keeps the head of the run.
        $opener->length -= $use;

        if (0 === $opener->length) {
            // Reuse the spent opener slot for the container. This preserves
            // its predecessor link and avoids a root-chain unlink scan.
            $emph = $openerNode;
            $tape->setKindId($emph, $kind);
            $tape->setEndOffset($emph, $closerStart + $use);
        } else {
            $tape->setEndOffset($openerNode, $openerEnd - $use);
            $this->state->reserveNode($openerEnd - $use);
            $emph = $tape->allocateClosed($kind, $this->root, $openerEnd - $use, $closerStart + $use, 0);
            $tape->linkNextSibling($openerNode, $emph);
        }

        if (ParseTape::NONE !== $firstInner && $firstInner !== $closerNode) {
            $tape->linkFirstChild($emph, $firstInner);
            $tape->linkNextSibling($lastInner, ParseTape::NONE);
        }

        $closer->length -= $use;

        if ($closer->length > 0) {
            // The closer node shrinks from the front: fresh node, relink.
            $this->state->reserveNode($closerStart + $use);
            $tail = $tape->allocateClosed(
                InlineKind::TEXT,
                $tape->parentOrdinal($closerNode),
                $closerStart + $use,
                $tape->endOffset($closerNode),
                0,
            );
            $tape->linkNextSibling($tail, $afterCloser);
            $tape->linkNextSibling($emph, $tail);
            $closer->node = $tail;
        } else {
            $tape->linkNextSibling($emph, $afterCloser);
        }
    }
}
