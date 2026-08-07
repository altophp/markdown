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

namespace Alto\Markdown\Parser;

use Alto\Markdown\Exception\NestingLimitException;
use Alto\Markdown\Parser\Block\BlockConstruct;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\Block\BlockStart;
use Alto\Markdown\Parser\Block\ContinueResult;
use Alto\Markdown\Parser\Block\FrontMatterParser;
use Alto\Markdown\Parser\Block\LinkReferenceDefinitionParser;
use Alto\Markdown\Parser\Block\ListItemParser;
use Alto\Markdown\Parser\Block\OpaqueLeafBlock;
use Alto\Markdown\Parser\Block\ParagraphReplacementValidator;
use Alto\Markdown\Profile\CompiledProfile;
use Alto\Markdown\Profile\ProfileCompiler;

/**
 * The three-phase block parsing loop: match open containers, try new
 * starts, add remaining text to the open leaf. Per-construct logic lives
 * behind BlockConstruct; this loop owns only document and paragraph
 * semantics (paragraph is the fallback leaf) and the open-block stack.
 *
 * Lazy continuation seam: a non-blank line that matches every open
 * container and starts nothing falls through to phase 3 and continues the
 * open paragraph. Container constructs (T2.7) rely on that fall-through;
 * setext headings (T2.4) use BlockStart::replacesParagraph.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class BlockParser
{
    /**
     * @var array<int, BlockConstruct>
     */
    private array $byKind = [];

    /**
     * @var list<BlockConstruct>
     */
    private readonly array $ordered;

    private int $maxNestingDepth = ParseOptions::UNBOUNDED_NESTING_DEPTH;

    private ?BlockCountBudget $blockCountBudget = null;

    /**
     * @var list<int>
     */
    private array $openOrdinals = [];

    /**
     * @var list<int>
     */
    private array $openKinds = [];

    /**
     * @var array<int, int>
     */
    private array $lastChild = [];

    /**
     * Sibling preceding each open block at open time, parallel to
     * openOrdinals; lets replacesParagraph relink over the absorbed node.
     *
     * @var list<int>
     */
    private array $openPrevSibling = [];

    private readonly LinkReferenceDefinitionParser $refdefs;

    /**
     * The profile's front matter construct, when it has one. Front matter
     * starts positionally rather than on a trigger byte, so the loop asks it
     * once before the first line instead of listing it in the start phase.
     */
    private readonly ?FrontMatterParser $frontMatter;

    private ReferenceMap $referenceMap;

    private readonly bool $taskListItems;

    private readonly ?int $githubAlertKind;

    /**
     * First-char dispatch: constructs to consult per first-non-space byte,
     * registry order preserved; always-consult constructs (indent-based)
     * appear in every bucket.
     *
     * @var array<int, list<BlockConstruct>>
     */
    private array $dispatch = [];

    /**
     * Kinds whose construct is an OpaqueLeafBlock, as a set. The root opaque
     * short circuit is tested once per input line, so its guard must not cost
     * a call frame and an instanceof (PD.1).
     *
     * @var array<int, true>
     */
    private array $opaqueKinds = [];

    /**
     * First bytes a list marker can take, as a set. The paragraph-interrupt
     * check runs on every fully matched paragraph line, and reaching the
     * memoized marker scan to learn that "T" is not a bullet cost three
     * frames per line of prose (PD.1).
     *
     * PHP casts the digit keys to integers; isset() folds a one-byte string
     * lookup to the same key, so the set answers both.
     *
     * @var array<int|string, true>
     */
    private const array LIST_MARKER_BYTES = [
        '-' => true,
        '+' => true,
        '*' => true,
        '0' => true,
        '1' => true,
        '2' => true,
        '3' => true,
        '4' => true,
        '5' => true,
        '6' => true,
        '7' => true,
        '8' => true,
        '9' => true,
    ];

    /**
     * Per-profile dispatch cache: the byKind map, the 256-bucket dispatch
     * table, and the refdef extractor depend only on the profile's construct
     * list, and the construct instances are already shared through the
     * profile, so building them once per profile instead of once per parse
     * removes a constant setup cost from every parse (PL.2b).
     *
     * @var \WeakMap<CompiledProfile, array{array<int, BlockConstruct>, array<int, list<BlockConstruct>>, LinkReferenceDefinitionParser, array<int, true>, FrontMatterParser|null}>|null
     */
    private static ?\WeakMap $compiledDispatch = null;

    public function __construct(?CompiledProfile $profile = null, ?int $excludedKind = null)
    {
        $profile ??= ProfileCompiler::commonmark();
        $constructs = $profile->blockConstructs();
        $this->ordered = null === $excludedKind
            ? $constructs
            : array_values(array_filter(
                $constructs,
                static fn (BlockConstruct $construct): bool => $excludedKind !== $construct->kind(),
            ));
        $this->taskListItems = $profile->taskListItems;
        $this->githubAlertKind = $profile->githubAlertKind;
        $this->referenceMap = new ReferenceMap();

        /** @var \WeakMap<CompiledProfile, array{array<int, BlockConstruct>, array<int, list<BlockConstruct>>, LinkReferenceDefinitionParser, array<int, true>, FrontMatterParser|null}> $cache */
        $cache = self::$compiledDispatch ??= new \WeakMap();

        if (null === $excludedKind && isset($cache[$profile])) {
            [$this->byKind, $this->dispatch, $this->refdefs, $this->opaqueKinds, $this->frontMatter] = $cache[$profile];

            return;
        }

        $refdefs = null;
        $frontMatter = null;

        foreach ($this->ordered as $construct) {
            $this->byKind[$construct->kind()] = $construct;

            if ($construct instanceof OpaqueLeafBlock) {
                $this->opaqueKinds[$construct->kind()] = true;
            }

            if ($construct instanceof LinkReferenceDefinitionParser) {
                $refdefs = $construct;
            }

            if ($construct instanceof FrontMatterParser) {
                $frontMatter = $construct;
            }
        }

        $this->refdefs = $refdefs ?? new LinkReferenceDefinitionParser();
        $this->frontMatter = $frontMatter;

        for ($byte = 0; $byte < 256; ++$byte) {
            $bucket = [];

            foreach ($this->ordered as $construct) {
                $triggers = $construct->triggerBytes();

                if (null === $triggers || str_contains($triggers, \chr($byte))) {
                    $bucket[] = $construct;
                }
            }

            $this->dispatch[$byte] = $bucket;
        }

        if (null === $excludedKind) {
            $cache[$profile] = [$this->byKind, $this->dispatch, $this->refdefs, $this->opaqueKinds, $this->frontMatter];
        }
    }

    /**
     * The reference map produced by the last parse() call; step 3 inline
     * link resolution reads it.
     */
    public function referenceMap(): ReferenceMap
    {
        return $this->referenceMap;
    }

    /**
     * Parses the whole input and returns the document ordinal.
     */
    public function parse(
        ParserState $state,
        int $maxNestingDepth = ParseOptions::UNBOUNDED_NESTING_DEPTH,
        int $maxBlockCount = ParseOptions::UNBOUNDED_BLOCK_COUNT,
        int $maxReferenceCount = ParseOptions::UNBOUNDED_REFERENCE_COUNT,
        bool $allowFrontMatter = true,
    ): int {
        $this->maxNestingDepth = $maxNestingDepth;
        $this->blockCountBudget = ParseOptions::UNBOUNDED_BLOCK_COUNT === $maxBlockCount
            ? null
            : new BlockCountBudget($maxBlockCount);
        $tape = $state->tape;
        $document = $tape->allocate(BlockKind::DOCUMENT, ParseTape::NONE, 0, 0);
        $this->openOrdinals = [$document];
        $this->openKinds = [BlockKind::DOCUMENT];
        $this->openPrevSibling = [ParseTape::NONE];
        $this->lastChild = [];
        $this->referenceMap = new ReferenceMap($maxReferenceCount);

        if (Instrumentation::$timing) {
            Instrumentation::enter('block-loop');
        }

        if ($state->scanner()->lineCount() > 0) {
            // Front matter is positional, not trigger-driven: it must be
            // decided before the first line reaches the start phase, where
            // `---` would open a thematic break instead. Opening it here also
            // puts it straight on the root opaque short circuit below, so the
            // rest of the block runs no general phase at all.
            if ($allowFrontMatter && null !== $this->frontMatter && $this->frontMatter->opensDocument($state)) {
                $this->openBlock($state, $this->frontMatter->kind(), $document, $state->offset);
            }

            do {
                $this->parseLine($state);
            } while ($state->nextLine());
        }

        if (Instrumentation::$timing) {
            Instrumentation::leave('block-loop');
        }

        $this->closeToDepth($state, 1);
        $tape->setEndOffset($document, \strlen($state->buffer->bytes));

        return $document;
    }

    private function parseLine(ParserState $state): void
    {
        $timing = Instrumentation::$timing;

        if (2 === \count($this->openOrdinals) && isset($this->opaqueKinds[$this->openKinds[1]])) {
            if ($timing) {
                Instrumentation::enter('block-opaque');
            }

            $opaque = $this->continueRootOpaqueLeaf($state);

            if ($timing) {
                Instrumentation::leave('block-opaque');
            }

            if ($opaque) {
                return;
            }
        }

        $blank = $state->lineIsBlank;

        if ($timing) {
            Instrumentation::enter('block-p1');
        }

        // Phase 1: how deep do the open blocks continue on this line?
        // Nothing closes yet: an unmatched tail ending in a paragraph may
        // still receive this line as a lazy continuation.
        $depth = 1;
        $openCount = \count($this->openOrdinals);
        // First non-space, cached against the cursor position it was taken
        // at. ParserState memoizes the same answer, but every phase of this
        // loop asked for it and each ask cost a call frame (PD.1). Only the
        // cursor moving invalidates it, and the cursor is a plain read.
        $fns = -1;
        $fnsAt = -1;

        while ($depth < $openCount) {
            $kind = $this->openKinds[$depth];

            if (BlockKind::PARAGRAPH === $kind) {
                // Blank relative to the cursor: container markers already
                // consumed on this line do not count as content.
                if ($blank) {
                    break;
                }

                if ($fnsAt !== $state->offset) {
                    $fnsAt = $state->offset;
                    $fns = $state->firstNonSpaceFrom();
                }

                if ($fns >= $state->lineContentEnd) {
                    break;
                }

                ++$depth;

                continue;
            }

            if ($timing) {
                ++Instrumentation::$blockTryContinueCalls;
            }

            $result = $this->byKind[$kind]->tryContinue($state, $this->openOrdinals[$depth]);

            if (ContinueResult::NotMatched === $result) {
                break;
            }

            if (ContinueResult::Closed === $result) {
                $this->closeToDepth($state, $depth);

                if ($timing) {
                    Instrumentation::leave('block-p1');
                }

                return;
            }

            ++$depth;
        }

        // A line whose remainder after consumed markers is only whitespace
        // acts as a blank line: paragraphs break, containers stay open.
        // For looseness the blank belongs to the deepest matched block:
        // blanks owned by a block quote or a fenced code block never count
        // (cmark's last-line-blank rule), so they are not recorded.
        $relativeBlank = $blank;

        if (!$relativeBlank) {
            if ($fnsAt !== $state->offset) {
                $fnsAt = $state->offset;
                $fns = $state->firstNonSpaceFrom();
            }

            $relativeBlank = $fns >= $state->lineContentEnd;
        }

        if ($relativeBlank) {
            $ownerKind = $this->openKinds[$depth - 1];

            if (BlockKind::BLOCK_QUOTE !== $ownerKind && BlockKind::FENCED_CODE !== $ownerKind) {
                $state->markLineBlank();
            }

            if (\count($this->openOrdinals) > $depth) {
                $this->closeToDepth($state, $depth);
            }

            if ($timing) {
                Instrumentation::leave('block-p1');
            }

            return;
        }

        if ($timing) {
            Instrumentation::leave('block-p1');
            Instrumentation::enter('block-p2');
        }

        $fullyMatched = $depth === $openCount;

        if ($fullyMatched
            && BlockKind::PARAGRAPH === $this->openKinds[\count($this->openKinds) - 1]
            && isset(self::LIST_MARKER_BYTES[$state->buffer->bytes[$fns]])
            && null !== ListItemParser::matchMarker($state)
        ) {
            $this->extractDefinitionsBeforeListStart($state);
            $depth = \count($this->openOrdinals);
        }

        // An indented line (4+ columns past the matched markers) can never
        // interrupt a lazily continuable paragraph: it is lazy text, and
        // indented code must not open mid-lazy.
        if (!$fullyMatched
            && BlockKind::PARAGRAPH === $this->openKinds[\count($this->openKinds) - 1]
            && $state->cursorIndent() >= 4
        ) {
            if ($fnsAt !== $state->offset) {
                $fnsAt = $state->offset;
                $fns = $state->firstNonSpaceFrom();
            }

            $this->appendParagraphLine($state, $this->openOrdinals[\count($this->openOrdinals) - 1], $fns, $state->lineContentEnd);

            if ($timing) {
                Instrumentation::leave('block-p2');
            }

            return;
        }

        // Phase 2: offer new starts to the constructs, anchored at the
        // deepest MATCHED container; the unmatched tail is still open.
        while (true) {
            $container = $this->openOrdinals[0];

            for ($i = min($depth, \count($this->openKinds)) - 1; $i > 0; --$i) {
                if (BlockKind::PARAGRAPH !== $this->openKinds[$i]) {
                    $container = $this->openOrdinals[$i];

                    break;
                }
            }

            // Paragraph-interrupt restrictions apply only to a fully
            // matched paragraph; a lazily continuable one does not protect
            // against constructs that outrank lazy continuation (a sibling
            // list, a setext underline). Type-7 HTML is the exception: it
            // never interrupts a paragraph, lazy or not, and is filtered
            // out below.
            $tipIsParagraph = $fullyMatched && BlockKind::PARAGRAPH === $this->openKinds[\count($this->openKinds) - 1];
            $start = null;
            $matchedConstruct = null;

            if ($fnsAt !== $state->offset) {
                $fnsAt = $state->offset;
                $fns = $state->firstNonSpaceFrom();
            }

            $candidates = $fns < $state->lineContentEnd
                ? $this->dispatch[\ord($state->buffer->bytes[$fns])]
                : [];

            foreach ($candidates as $construct) {
                if ($timing) {
                    ++Instrumentation::$blockTryStartCalls;
                }

                $start = $construct->tryStart($state, $container, $tipIsParagraph);

                // A setext underline is never lazy: it only absorbs a fully
                // matched paragraph. Later constructs still get the line.
                if (null !== $start && $start->replacesParagraph && !$fullyMatched) {
                    $start = null;

                    continue;
                }

                // A type-7 HTML block cannot interrupt a paragraph, including
                // a lazily continuable one, so it stays paragraph text here.
                if (null !== $start
                    && !$fullyMatched
                    && BlockKind::HTML_BLOCK === $start->kind
                    && 7 === $start->flags
                    && BlockKind::PARAGRAPH === $this->openKinds[\count($this->openKinds) - 1]
                ) {
                    $start = null;

                    continue;
                }

                if (null !== $start) {
                    $matchedConstruct = $construct;

                    break;
                }
            }

            if (null === $start) {
                break;
            }

            if ($start->replacesParagraph
                && BlockKind::PARAGRAPH === $this->openKinds[\count($this->openKinds) - 1]
                && $matchedConstruct instanceof ParagraphReplacementValidator
                && !$matchedConstruct->canReplaceParagraph($state, $state->tape, $this->openOrdinals[\count($this->openOrdinals) - 1], $start)
            ) {
                break;
            }

            // A real start ends the lazy window: the unmatched tail closes.
            if (\count($this->openOrdinals) > $depth) {
                $this->closeToDepth($state, $depth);
            }

            $fullyMatched = true;
            $contentOffset = $start->contentOffset;

            if ($start->replacesParagraph && BlockKind::PARAGRAPH === $this->openKinds[\count($this->openKinds) - 1]) {
                // Q-004: reference definitions leave the paragraph before a
                // setext underline can absorb it. All-definitions cancels
                // the setext entirely: the underline is ordinary text.
                if (!$this->extractBeforeAbsorb($state)) {
                    continue;
                }

                if (null !== $start->paragraphWrapperKind && null !== $start->paragraphChildKind) {
                    $ordinal = $this->absorbParagraphIntoContainer(
                        $state,
                        $start->paragraphWrapperKind,
                        $start->paragraphChildKind,
                        $start->kind,
                        $start->startOffset ?? $start->contentOffset,
                    );

                    if (0 !== $start->flags) {
                        $state->tape->setFlags($ordinal, $start->flags);
                    }

                    if (null !== $start->payload) {
                        $state->tape->setPayload($ordinal, $start->payload);
                    }

                    if (null !== $start->extensionState) {
                        $state->tape->setExtensionBlockState($ordinal, $start->extensionState);
                    }
                } else {
                    $ordinal = $this->absorbParagraph($state, $start->kind);
                }
            } else {
                $tip = \count($this->openKinds) - 1;

                if (BlockKind::PARAGRAPH === $this->openKinds[$tip]) {
                    $this->closeToDepth($state, $tip);
                }

                // A list contains only items: any other construct starting
                // at list level closes the list and lands beside it.
                if (BlockKind::LIST_ITEM !== $start->kind) {
                    while (BlockKind::LIST === $this->openKinds[\count($this->openKinds) - 1]) {
                        $this->closeToDepth($state, \count($this->openOrdinals) - 1);
                    }
                }

                // The tip is provably not a paragraph here: one just closed if
                // it was open, so the deepest container is the tip itself and
                // the downward walk has nothing to skip.
                $ordinal = $this->openBlock(
                    $state,
                    $start->kind,
                    $this->openOrdinals[\count($this->openOrdinals) - 1],
                    $start->startOffset ?? $start->contentOffset,
                );

                if (0 !== $start->flags) {
                    $state->tape->setFlags($ordinal, $start->flags);
                }

                if (null !== $start->payload) {
                    $state->tape->setPayload($ordinal, $start->payload);
                }

                if (null !== $start->extensionState) {
                    $state->tape->setExtensionBlockState($ordinal, $start->extensionState);
                    $state->tape->setEndOffset($ordinal, $start->contentOffset);
                }

                if ($this->taskListItems && BlockKind::LIST_ITEM === $start->kind) {
                    $contentOffset = $this->taskListAdjustedContentOffset($state, $start, $ordinal) ?? $start->contentOffset;
                }
            }

            $depth = \count($this->openOrdinals);
            $fnsAt = -1;
            $state->advanceTo(min($contentOffset, $state->lineContentEnd));

            if (0 !== $state->pendingPad && $this->startConsumesLeadingIndentPad($start->kind)) {
                $state->discardPendingPad();
            }

            if ($start->pad > 0) {
                $state->addPendingPad($start->pad);
            }

            if (!$start->isContainer) {
                // A leaf construct owns the rest of the line; Closed means
                // the block ends with it (single-line leaves like ATX).
                if (ContinueResult::Closed === $this->byKind[$start->kind]->tryContinue($state, $ordinal)) {
                    $this->closeToDepth($state, \count($this->openOrdinals) - 1);
                }

                if ($timing) {
                    Instrumentation::leave('block-p2');
                }

                return;
            }
        }

        if ($timing) {
            Instrumentation::leave('block-p2');
        }

        $tipIndex = \count($this->openOrdinals) - 1;
        $contentEnd = $state->lineContentEnd;

        if ($fnsAt !== $state->offset) {
            $fns = $state->firstNonSpaceFrom();
        }

        if (!$fullyMatched) {
            if (BlockKind::PARAGRAPH === $this->openKinds[$tipIndex]) {
                // Lazy continuation: the line joins the unmatched paragraph
                // and nothing closes. Leading whitespace is stripped from the
                // content (spec 4.8: paragraph lines have leading spaces or
                // tabs removed) so cross-line inline constructs never see it.
                $this->appendParagraphLine($state, $this->openOrdinals[$tipIndex], $fns, $contentEnd);

                return;
            }

            if (\count($this->openOrdinals) > $depth) {
                $this->closeToDepth($state, $depth);
            }

            $tipIndex = \count($this->openOrdinals) - 1;
        }

        // Phase 3: remaining text feeds the open paragraph. A leaf that
        // consumed the whole line (code, html) or a whitespace-only rest
        // (empty list item tail) leaves nothing to place.
        if (BlockKind::PARAGRAPH === $this->openKinds[$tipIndex]) {
            $this->appendParagraphLine($state, $this->openOrdinals[$tipIndex], $fns, $contentEnd);

            return;
        }

        if ($fns >= $contentEnd) {
            return;
        }

        // The tip is not a paragraph on this branch, so it is already the
        // deepest container that can hold the new one.
        $paragraph = $this->openBlock($state, BlockKind::PARAGRAPH, $this->openOrdinals[$tipIndex], $fns);
        $this->appendParagraphLine($state, $paragraph, $fns, $contentEnd);
    }

    /**
     * A root-level opaque leaf owns the whole current line. Nothing beneath
     * it can open, interrupt, or lazily continue, so the general three-phase
     * block loop has no work to do until that leaf ends.
     */
    private function continueRootOpaqueLeaf(ParserState $state): bool
    {
        $kind = $this->openKinds[1];
        $result = $this->byKind[$kind]->tryContinue($state, $this->openOrdinals[1]);

        if (ContinueResult::NotMatched === $result) {
            $this->closeToDepth($state, 1);

            return false;
        }

        if (ContinueResult::Closed === $result) {
            $this->closeToDepth($state, 1);

            return true;
        }

        if (BlockKind::HTML_BLOCK === $kind && $state->lineIsBlank) {
            $state->markLineBlank();
        }

        return true;
    }

    private function taskListAdjustedContentOffset(ParserState $state, BlockStart $start, int $ordinal): ?int
    {
        $offset = $start->contentOffset;
        $end = $state->lineContentEnd;
        $buffer = $state->buffer;

        while ($offset < $end) {
            $byte = $buffer->byteAt($offset);

            if (0x20 !== $byte && 0x09 !== $byte) {
                break;
            }

            ++$offset;
        }

        if ($offset + 3 >= $end || 0x5B !== $buffer->byteAt($offset) || 0x5D !== $buffer->byteAt($offset + 2)) {
            return null;
        }

        $marker = $buffer->byteAt($offset + 1);
        $separator = $buffer->byteAt($offset + 3);

        if (0x20 !== $separator && 0x09 !== $separator) {
            return null;
        }

        if (0x20 === $marker || 0x09 === $marker) {
            $state->tape->setPayload($ordinal, 'task:unchecked');

            return $offset + 4;
        }

        if (0x78 === $marker || 0x58 === $marker) {
            $state->tape->setPayload($ordinal, 'task:checked');

            return $offset + 4;
        }

        return null;
    }

    /**
     * Records one content line on a paragraph: extends its end offset and
     * appends the line's content byte range (markers excluded) to the
     * payload pair list "cs:ce;cs:ce;...". Containers make paragraph content
     * non-contiguous, so the renderer reads the pairs, never the raw span.
     */
    private function appendParagraphLine(ParserState $state, int $ordinal, int $contentStart, int $contentEnd): void
    {
        $timing = Instrumentation::$timing;

        if ($timing) {
            Instrumentation::enter('block-p3');
            ++Instrumentation::$blockParagraphAppends;
        }

        $tape = $state->tape;
        $tape->setEndOffset($ordinal, $contentEnd);

        $pair = $contentStart.':'.$contentEnd;

        if (0 !== $state->pendingPad) {
            $pair .= ':'.$state->takePendingPad();
        }

        $tape->appendPayloadPart($ordinal, $pair, ';');

        if ($timing) {
            Instrumentation::leave('block-p3');
        }
    }

    private function startConsumesLeadingIndentPad(int $kind): bool
    {
        $coreConsumes = match ($kind) {
            BlockKind::ATX_HEADING,
            BlockKind::SETEXT_HEADING,
            BlockKind::FENCED_CODE,
            BlockKind::BLOCK_QUOTE,
            BlockKind::LIST_ITEM,
            BlockKind::THEMATIC_BREAK => true,
            default => false,
        };

        return $coreConsumes || ($this->byKind[$kind] ?? null) instanceof ParagraphReplacementValidator;
    }

    /**
     * Closes open blocks until only $depth remain, deepest first.
     */
    private function closeToDepth(ParserState $state, int $depth): void
    {
        $timing = Instrumentation::$timing;

        if ($timing) {
            Instrumentation::enter('block-close');
        }

        while (\count($this->openOrdinals) > $depth) {
            $ordinal = array_pop($this->openOrdinals);
            $kind = array_pop($this->openKinds);
            $prevSibling = array_pop($this->openPrevSibling);

            if (null === $ordinal || null === $kind) {
                break;
            }

            if (BlockKind::PARAGRAPH === $kind) {
                // Reference definitions are extracted out of the closing
                // paragraph (T2.8); the extractor does all tape surgery and
                // returns the parent's new last child.
                $parent = $state->tape->parentOrdinal($ordinal);
                $this->lastChild[$parent] = $this->refdefs->extractInto(
                    $state,
                    $this->referenceMap,
                    $ordinal,
                    $parent,
                    $prevSibling ?? ParseTape::NONE,
                    $this->blockCountBudget,
                );

                continue;
            }

            if (BlockKind::DOCUMENT !== $kind) {
                $this->byKind[$kind]->close($state, $ordinal);

                if (null !== $this->githubAlertKind && BlockKind::BLOCK_QUOTE === $kind) {
                    $this->promoteGitHubAlert($state, $ordinal, $this->githubAlertKind);
                }
            }
        }

        if ($timing) {
            Instrumentation::leave('block-close');
        }
    }

    private function promoteGitHubAlert(ParserState $state, int $ordinal, int $alertKind): void
    {
        $tape = $state->tape;
        $paragraph = $tape->firstChildOrdinal($ordinal);

        if (ParseTape::NONE === $paragraph || BlockKind::PARAGRAPH !== $tape->kindId($paragraph)) {
            return;
        }

        $payload = $tape->payload($paragraph);

        if (null === $payload || '' === $payload) {
            return;
        }

        $pairs = explode(';', $payload);
        $first = explode(':', $pairs[0], 4);
        $start = (int) $first[0];
        $end = (int) ($first[1] ?? 0);
        $line = $state->buffer->substring($start, $end);

        if (1 !== preg_match('/^\\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)](?:[ \\t]+)?/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return;
        }

        $markerEnd = $start + \strlen($match[0][0]);
        $tape->setKindId($ordinal, $alertKind);
        $tape->setPayload($ordinal, strtolower($match[1][0]));

        if ($markerEnd < $end) {
            $pairs[0] = $markerEnd.':'.$end;
        } else {
            array_shift($pairs);
        }

        $tape->setPayload($paragraph, [] === $pairs ? $end.':'.$end : implode(';', $pairs));
    }

    private function openBlock(ParserState $state, int $kind, int $parent, int $startOffset): int
    {
        if (0 !== $this->maxNestingDepth && \count($this->openOrdinals) > $this->maxNestingDepth) {
            throw new NestingLimitException(\sprintf('Input nests blocks more than %d levels deep at byte offset %d.', $this->maxNestingDepth, $startOffset));
        }

        $this->blockCountBudget?->add($startOffset);

        $previous = $this->lastChild[$parent] ?? ParseTape::NONE;
        // Allocate and link in one call: opening a block is the per-block
        // fixed cost this package targets, and the split cost two frames for
        // work the tape can do in one (PD.1).
        $ordinal = $state->tape->appendChild($kind, $parent, $previous, $startOffset, ParseTape::NONE, 0);

        $this->lastChild[$parent] = $ordinal;
        $this->openOrdinals[] = $ordinal;
        $this->openKinds[] = $kind;
        $this->openPrevSibling[] = $previous;

        return $ordinal;
    }

    /**
     * Extracts leading reference definitions out of the open tip paragraph
     * before a setext absorb. Returns true when a paragraph remains to be
     * absorbed (the original, or a survivor holding the leftover lines,
     * which replaces the tip). Returns false when the paragraph was
     * entirely definitions: the setext start is cancelled and the caller
     * retries the line as ordinary content.
     */
    private function extractBeforeAbsorb(ParserState $state): bool
    {
        $tape = $state->tape;
        $tip = \count($this->openOrdinals) - 1;
        $paragraph = $this->openOrdinals[$tip];
        $prevSibling = $this->openPrevSibling[$tip];
        $parent = $tape->parentOrdinal($paragraph);

        $newLast = $this->refdefs->extractInto(
            $state,
            $this->referenceMap,
            $paragraph,
            $parent,
            $prevSibling,
            $this->blockCountBudget,
        );

        if ($newLast === $paragraph) {
            return true;
        }

        // The original paragraph was dismantled: rewrite the stack tip.
        array_pop($this->openOrdinals);
        array_pop($this->openKinds);
        array_pop($this->openPrevSibling);
        $this->lastChild[$parent] = $newLast;

        if (BlockKind::PARAGRAPH !== $tape->kindId($newLast)) {
            return false;
        }

        // Survivor paragraph: reopen it as the tip so absorb can take it.
        $before = ParseTape::NONE;
        $child = $tape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $child && $child !== $newLast) {
            $before = $child;
            $child = $tape->nextSiblingOrdinal($child);
        }

        $this->openOrdinals[] = $newLast;
        $this->openKinds[] = BlockKind::PARAGRAPH;
        $this->openPrevSibling[] = $before;

        return true;
    }

    /**
     * Reference definitions at the start of a paragraph are not paragraph
     * content. Before applying paragraph-interrupt restrictions to a list
     * marker, peel them out: if no paragraph survives, even `0.` is an
     * ordinary list start rather than a paragraph interruption.
     */
    private function extractDefinitionsBeforeListStart(ParserState $state): void
    {
        $tape = $state->tape;
        $tip = \count($this->openOrdinals) - 1;
        $paragraph = $this->openOrdinals[$tip];
        $prevSibling = $this->openPrevSibling[$tip];
        $parent = $tape->parentOrdinal($paragraph);

        $newLast = $this->refdefs->extractInto(
            $state,
            $this->referenceMap,
            $paragraph,
            $parent,
            $prevSibling,
            $this->blockCountBudget,
        );

        if ($newLast === $paragraph) {
            return;
        }

        array_pop($this->openOrdinals);
        array_pop($this->openKinds);
        array_pop($this->openPrevSibling);
        $this->lastChild[$parent] = $newLast;

        if (BlockKind::PARAGRAPH !== $tape->kindId($newLast)) {
            return;
        }

        $before = ParseTape::NONE;
        $child = $tape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $child && $child !== $newLast) {
            $before = $child;
            $child = $tape->nextSiblingOrdinal($child);
        }

        $this->openOrdinals[] = $newLast;
        $this->openKinds[] = BlockKind::PARAGRAPH;
        $this->openPrevSibling[] = $before;
    }

    /**
     * The setext case: the new block takes the open paragraph's place and
     * source start; the paragraph's slot is unlinked (tape slots are
     * append-only, so it stays allocated but unreachable). The new block's
     * payload is preset to the absorbed content range "start:end".
     */
    private function absorbParagraph(ParserState $state, int $kind): int
    {
        $tape = $state->tape;
        $depth = \count($this->openOrdinals) - 1;
        $paragraph = $this->openOrdinals[$depth];
        $prevSibling = $this->openPrevSibling[$depth];
        $parent = $tape->parentOrdinal($paragraph);

        array_pop($this->openOrdinals);
        array_pop($this->openKinds);
        array_pop($this->openPrevSibling);

        $contentStart = $tape->startOffset($paragraph);
        $contentEnd = $tape->endOffset($paragraph);

        $ordinal = $tape->allocate($kind, $parent, $contentStart, 0);

        if (ParseTape::NONE === $prevSibling) {
            $tape->linkFirstChild($parent, $ordinal);
        } else {
            $tape->linkNextSibling($prevSibling, $ordinal);
        }

        $this->lastChild[$parent] = $ordinal;
        $this->openOrdinals[] = $ordinal;
        $this->openKinds[] = $kind;
        $this->openPrevSibling[] = $prevSibling;

        // The absorbed paragraph's content pairs become the heading content.
        $tape->setPayload($ordinal, $tape->payload($paragraph) ?? \sprintf('%d:%d', $contentStart, $contentEnd));

        return $ordinal;
    }

    /**
     * Replace a paragraph with a container holding one closed child per
     * paragraph content line, then open the active construct after them.
     *
     * A previously closed adjacent container of the same kind is reopened and
     * extended. This is what merges consecutive definition-list terms without
     * a document-wide mutation pass.
     */
    private function absorbParagraphIntoContainer(
        ParserState $state,
        int $wrapperKind,
        int $paragraphChildKind,
        int $activeKind,
        int $activeStartOffset,
    ): int {
        $tape = $state->tape;
        $depth = \count($this->openOrdinals) - 1;
        $paragraph = $this->openOrdinals[$depth];
        $previous = $this->openPrevSibling[$depth];
        $parent = $tape->parentOrdinal($paragraph);

        array_pop($this->openOrdinals);
        array_pop($this->openKinds);
        array_pop($this->openPrevSibling);

        $wrapper = ParseTape::NONE;
        $beforeWrapper = ParseTape::NONE;

        if (ParseTape::NONE !== $previous && $wrapperKind === $tape->kindId($previous)) {
            $wrapper = $previous;
            $child = $tape->firstChildOrdinal($parent);

            while (ParseTape::NONE !== $child && $child !== $wrapper) {
                $beforeWrapper = $child;
                $child = $tape->nextSiblingOrdinal($child);
            }

            $tape->linkNextSibling($wrapper, ParseTape::NONE);
            $this->lastChild[$parent] = $wrapper;
        } else {
            $this->blockCountBudget?->add($tape->startOffset($paragraph));
            $wrapper = $tape->allocate($wrapperKind, $parent, $tape->startOffset($paragraph), 0);

            if (ParseTape::NONE === $previous) {
                $tape->linkFirstChild($parent, $wrapper);
            } else {
                $tape->linkNextSibling($previous, $wrapper);
            }

            $beforeWrapper = $previous;
            $this->lastChild[$parent] = $wrapper;
        }

        $this->openOrdinals[] = $wrapper;
        $this->openKinds[] = $wrapperKind;
        $this->openPrevSibling[] = $beforeWrapper;

        $last = $this->lastChildOrdinal($tape, $wrapper);
        $payload = $tape->payload($paragraph) ?? \sprintf(
            '%d:%d',
            $tape->startOffset($paragraph),
            $tape->endOffset($paragraph),
        );

        foreach (explode(';', $payload) as $part) {
            $range = explode(':', $part, 3);
            $start = (int) $range[0];
            $end = (int) ($range[1] ?? $start);

            $this->blockCountBudget?->add($start);
            $term = $tape->appendChild(
                $paragraphChildKind,
                $wrapper,
                $last,
                $start,
                $end,
                0,
                payload: $part,
            );
            $last = $term;
        }

        $this->blockCountBudget?->add($activeStartOffset);
        $active = $tape->appendChild(
            $activeKind,
            $wrapper,
            $last,
            $activeStartOffset,
            ParseTape::NONE,
            0,
        );
        $this->lastChild[$wrapper] = $active;
        $this->openOrdinals[] = $active;
        $this->openKinds[] = $activeKind;
        $this->openPrevSibling[] = $last;

        return $active;
    }

    private function lastChildOrdinal(ParseTape $tape, int $parent): int
    {
        $last = ParseTape::NONE;
        $child = $tape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $child) {
            $last = $child;
            $child = $tape->nextSiblingOrdinal($child);
        }

        return $last;
    }
}
