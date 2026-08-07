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

use Alto\Markdown\Parser\InlineCountBudget;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use Alto\Markdown\Profile\CompiledProfile;
use Alto\Markdown\Profile\ProfileCompiler;

/**
 * The inline phase for one block: content ranges in, inline tape out.
 * The block's per-line content pairs (container markers already excluded)
 * are joined into one scannable string (InlineContent), because inline
 * constructs can span lines; joints render as SOFT_BREAK or HARD_BREAK.
 *
 * Scanning is byte-dispatched: strcspn jumps over literal text to the
 * next special byte, registered constructs get first claim on it, and an
 * unclaimed special joins the text run. Emphasis delimiters and link
 * brackets are wave-3 seams: their processors extend this dispatch, they
 * do not rewrite the loop.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InlineParser
{
    /**
     * Constructs per trigger byte, registry order preserved.
     *
     * @var array<int, list<InlineConstruct>>
     */
    private array $dispatch = [];

    /**
     * Every special byte for the strcspn jump; "\n" is the parser's own.
     */
    private readonly string $specials;

    private readonly EmphasisProcessor $emphasis;

    private readonly LinkResolver $links;

    private readonly bool $strikethrough;

    /**
     * Constructs located by content search instead of by trigger byte.
     *
     * @var list<ContentScannedInlineConstruct>
     */
    private readonly array $scanned;

    public function __construct(?CompiledProfile $profile = null)
    {
        ++Instrumentation::$inlineParserConstructions;
        $this->emphasis = new EmphasisProcessor();
        $this->links = new LinkResolver($this->emphasis);
        $profile ??= ProfileCompiler::commonmark();
        $specials = $profile->inlineSpecialBytes;
        $this->strikethrough = $profile->strikethrough;
        $this->scanned = $profile->contentScannedConstructs;

        foreach ($profile->inlineConstructs() as $construct) {
            if ($construct instanceof ContentScannedInlineConstruct) {
                continue;
            }

            foreach (str_split($construct->triggerBytes()) as $byte) {
                $this->dispatch[\ord($byte)][] = $construct;
            }
        }

        $this->specials = $specials;
    }

    /**
     * The earliest offset at or after $from where a content-scanned
     * construct could start, or -1.
     */
    private function scannedCandidate(string $text, int $from): int
    {
        $best = -1;

        foreach ($this->scanned as $construct) {
            $at = $construct->nextCandidate($text, $from);

            if (-1 !== $at && (-1 === $best || $at < $best)) {
                $best = $at;
            }
        }

        return $best;
    }

    /**
     * Parses one block's content into a fresh inline tape whose root is
     * ordinal 0.
     *
     * @param list<array{int, int, int}> $pairs content ranges (start, end, pad)
     */
    public function parse(
        SourceBuffer $buffer,
        array $pairs,
        ReferenceMap $referenceMap,
        ?InlineCountBudget $inlineCountBudget = null,
        bool $sourceOffsetsAreOriginal = true,
    ): ParseTape {
        ++Instrumentation::$inlineParses;
        $budget = $inlineCountBudget?->begin();

        $tape = new ParseTape();
        $root = $tape->allocate(InlineKind::ROOT, ParseTape::NONE, $pairs[0][0] ?? 0, 0);

        if ([] === $pairs) {
            $budget?->commit();

            return $tape;
        }

        if (Instrumentation::$timing) {
            Instrumentation::enter('inline-build');
        }

        $content = InlineContent::fromPairs($buffer->bytes, $pairs);

        if (Instrumentation::$timing) {
            Instrumentation::leave('inline-build');
            Instrumentation::enter('inline-scan');
        }

        $state = null === $budget
            ? new InlineState($content, $tape, $referenceMap, $root)
            : new BudgetedInlineState(
                $content,
                $tape,
                $referenceMap,
                $root,
                $budget,
                $sourceOffsetsAreOriginal,
            );
        $wrapper = new TapeEmphasisWrapper($tape, $root, $state);
        $text = $content->text;
        $length = \strlen($text);

        // The block's content is trimmed as a whole: leading and trailing
        // whitespace is layout (the final flush handles the tail).
        $state->skip(strspn($text, " \t"));

        /** @var list<Delimiter> $delimiters */
        $delimiters = [];

        /** @var list<Bracket> $brackets */
        $brackets = [];

        // Where a content-scanned construct could start next. Recomputed
        // only once the cursor has passed it, so a construct that consumed
        // a region (a code span, raw HTML) moves the search past its
        // interior and candidates inside it never come back.
        $candidate = $this->scannedCandidate($text, $state->offset());

        while (!$state->atEnd()) {
            $offset = $state->offset();

            if ($candidate >= 0 && $candidate < $offset) {
                $candidate = $this->scannedCandidate($text, $offset);
            }

            // The jump stops at the candidate, so declining one costs the
            // bytes up to the next candidate rather than a fresh scan of
            // the rest of the block.
            $limit = $candidate >= $offset ? $candidate - $offset : $length - $offset;
            $jump = strcspn($text, $this->specials, $offset, $limit);

            if ($jump === $limit && $candidate >= $offset) {
                $state->advance($limit);
                $matched = false;

                foreach ($this->scanned as $construct) {
                    if ($construct->tryParse($state)) {
                        $matched = true;

                        break;
                    }
                }

                if (!$matched) {
                    $state->advance(1);
                }

                continue;
            }

            if ($jump > 0) {
                $state->advance($jump);

                continue;
            }

            if ("\n" === $text[$offset]) {
                $joint = $content->jointAt($offset);
                $state->flushText($content->breakChops[$joint]);
                $state->emit($content->breakKinds[$joint], $offset + 1);
                $state->skip(strspn($text, " \t", $offset + 1, $length - $offset - 1));

                continue;
            }

            $byte = $text[$offset];

            if ('[' === $byte) {
                [$node, $previous] = $state->emitBracket($offset + 1);
                $brackets[] = new Bracket($node, false, \count($delimiters), $offset + 1, $previous);

                continue;
            }

            if ('!' === $byte) {
                if ($offset + 1 < $length && '[' === $text[$offset + 1]) {
                    [$node, $previous] = $state->emitBracket($offset + 2);
                    $brackets[] = new Bracket($node, true, \count($delimiters), $offset + 2, $previous);
                } else {
                    $state->advance(1);
                }

                continue;
            }

            if (']' === $byte) {
                $state->flushText();

                if (Instrumentation::$timing) {
                    Instrumentation::enter('link-resolve');
                }

                $next = $this->links->resolve($state, $content, $brackets, $delimiters, $offset, $wrapper);

                if (Instrumentation::$timing) {
                    Instrumentation::leave('link-resolve');
                }

                if (null === $next) {
                    $state->advance(1);
                } else {
                    $state->skip($next - $offset);
                }

                continue;
            }

            if ('*' === $byte || '_' === $byte) {
                $run = strspn($text, $byte, $offset);
                $before = CharClass::before($text, $offset);
                $after = CharClass::after($text, $offset + $run);

                $left = CharClass::WHITESPACE !== $after
                    && (CharClass::PUNCTUATION !== $after || CharClass::OTHER !== $before);
                $right = CharClass::WHITESPACE !== $before
                    && (CharClass::PUNCTUATION !== $before || CharClass::OTHER !== $after);

                if ('*' === $byte) {
                    $canOpen = $left;
                    $canClose = $right;
                } else {
                    $canOpen = $left && (!$right || CharClass::PUNCTUATION === $before);
                    $canClose = $right && (!$left || CharClass::PUNCTUATION === $after);
                }

                $node = $state->emit(InlineKind::TEXT, $offset + $run);
                $delimiters[] = new Delimiter($node, $byte, $run, $canOpen, $canClose);

                continue;
            }

            if ($this->strikethrough && '~' === $byte) {
                $run = strspn($text, '~', $offset);
                $node = $state->emit(InlineKind::TEXT, $offset + $run);
                $delimiters[] = new Delimiter($node, '~', $run, true, true);

                continue;
            }

            $claimed = false;

            foreach ($this->dispatch[\ord($text[$offset])] ?? [] as $construct) {
                if ($construct->tryParse($state)) {
                    $claimed = true;

                    break;
                }
            }

            if (!$claimed) {
                $state->advance(1);
            }
        }

        // Final flush strips block-final whitespace: it is layout, and no
        // construct claimed it.
        $state->flushText(strspn(strrev($text), " \t"));

        if (Instrumentation::$timing) {
            Instrumentation::leave('inline-scan');
            Instrumentation::enter('emphasis');
        }

        $this->emphasis->process($wrapper, $delimiters);

        if (Instrumentation::$timing) {
            Instrumentation::leave('emphasis');
        }

        $tape->setEndOffset($root, $content->sourceOffset($length));
        $budget?->commit();

        return $tape;
    }
}
