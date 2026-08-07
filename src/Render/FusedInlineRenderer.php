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

namespace Alto\Markdown\Render;

use Alto\Markdown\Exception\RenderException;
use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Document\PlannedHtmlInlineRenderer;
use Alto\Markdown\Extension\Html\CompiledHtmlDecoratorChain;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;
use Alto\Markdown\Extension\Inline\AccumulatingHtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer as ExtensionHtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\InlineLinkSemantics;
use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\LinkLikeInlineRenderer;
use Alto\Markdown\Extension\LinkRewrite\CompiledLinkDestinationRewriter;
use Alto\Markdown\Parser\Inline\Bracket;
use Alto\Markdown\Parser\Inline\CharClass;
use Alto\Markdown\Parser\Inline\ContentScannedInlineConstruct;
use Alto\Markdown\Parser\Inline\Delimiter;
use Alto\Markdown\Parser\Inline\EmphasisProcessor;
use Alto\Markdown\Parser\Inline\EmphasisWrapper;
use Alto\Markdown\Parser\Inline\Href;
use Alto\Markdown\Parser\Inline\InlineConstruct;
use Alto\Markdown\Parser\Inline\InlineContent;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Inline\InlineScanState;
use Alto\Markdown\Parser\Inline\LinkResolver;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use Alto\Markdown\Profile\CompiledProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use Alto\Markdown\Source\SourceRange;

/**
 * Fused inline emission for the direct conversion lane: one scan over a
 * block's joined inline content that runs the same byte dispatch,
 * delimiter, bracket, and reference algorithms as InlineParser but emits
 * HTML segments as it goes. Literal runs are escaped and appended
 * immediately; each emphasis delimiter run and each bracket reserves one
 * segment slot, patched to its tags when it resolves and collapsing to
 * literal text when it does not. No inline ParseTape is allocated and no
 * second traversal happens.
 *
 * A delimiter slot composes as closeTags.text.openTags: close tags
 * accumulate left to right, the literal remnant shrinks as wraps consume
 * characters, and open tags grow leftward (an outer wrap's tag prepends
 * before the inner one already there). Proper nesting of the flat tag
 * stream follows from the emphasis algorithm's own well-nesting.
 *
 * Image alt text is flattened in parallel: while an image bracket is
 * open, every emission also records its plain-text form, so a resolving
 * image patches its slot to a complete img tag and blanks the label
 * segments (recording the flattened alt for an enclosing image).
 *
 * The one construct that falls back to the tape path is GFM nested
 * strong emphasis (a STRONG wrap whose span contains another STRONG's
 * open tag): the tape renderer suppresses directly nested strong tags
 * through tree recursion that a flat segment stream cannot reproduce
 * cheaply, so the block re-renders through HtmlInlineRenderer.
 * Fallbacks are counted by Instrumentation.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FusedInlineRenderer implements BlockInlineRenderer, InlineScanState, EmphasisWrapper
{
    /**
     * Stream positions for the nested-strong guard: part index in the
     * high bits, the within-slot tag position in the low bits.
     */
    private const int PART_SHIFT = 32;

    /**
     * Constructs per trigger byte, registry order preserved.
     *
     * @var array<int, list<InlineConstruct>>
     */
    private array $dispatch = [];

    /**
     * Every special byte for the strcspn jump; "\n" is the scanner's own.
     */
    private readonly string $specials;

    /**
     * Constructs located by content search instead of by trigger byte.
     *
     * @var list<ContentScannedInlineConstruct>
     */
    private readonly array $scanned;

    private readonly bool $strikethrough;

    private readonly bool $tagFilter;

    /**
     * @var array<int, ExtensionHtmlInlineRenderer>
     */
    private readonly array $customRenderers;

    /**
     * Link semantics by custom kind.
     *
     * @var array<int, InlineLinkSemantics>
     */
    private readonly array $inlineLinks;

    private readonly ?CompiledHtmlDecoratorChain $htmlLinkDecorators;

    private readonly ?CompiledLinkDestinationRewriter $linkDestinationRewriter;

    /**
     * @var array<int, string>
     */
    private readonly array $customKindNames;

    private readonly EmphasisProcessor $emphasis;

    private readonly HtmlInlineRenderer $tape;

    private HtmlPolicy $policy;

    private ?DocumentRenderPlan $documentRenderPlan = null;

    private InlineContent $content;

    private string $text = '';

    private string $sourceBytes = '';

    private bool $hardBreaks = true;

    private bool $rangesAreOriginal = true;

    private ReferenceMap $references;

    private int $offset = 0;

    private int $textStart = 0;

    /**
     * Composed HTML segments in stream order (contiguous integer keys);
     * slot indexes are patched in place.
     *
     * @var array<int, string>
     */
    private array $parts = [];

    /**
     * Literal HTML accumulating since the last reserved segment.
     */
    private string $current = '';

    /**
     * @var array<int, string> delimiter character per slot part
     */
    private array $slotChar = [];

    /**
     * @var array<int, int> remaining literal run length per slot part
     */
    private array $slotLength = [];

    /**
     * @var array<int, string> open tags per slot, outermost first
     */
    private array $slotOpen = [];

    /**
     * @var array<int, string> close tags per slot, innermost first
     */
    private array $slotClose = [];

    /**
     * @var array<int, int>
     */
    private array $slotOpenCount = [];

    /**
     * @var array<int, int>
     */
    private array $slotCloseCount = [];

    /**
     * @var list<Delimiter>
     */
    private array $delimiters = [];

    /**
     * @var list<Bracket>
     */
    private array $brackets = [];

    private int $imageDepth = 0;

    private bool $altTracking = false;

    /**
     * Plain-text (alt) form per part index, recorded while an image
     * bracket is open. Delimiter slots derive their alt from the slot
     * remnant instead.
     *
     * @var array<int, string>
     */
    private array $altParts = [];

    private string $altCurrent = '';

    /**
     * Sorted stream positions of STRONG open tags, for the nested-strong
     * fallback guard (strikethrough profiles only).
     *
     * @var list<int>
     */
    private array $strongOpens = [];

    public function __construct(?CompiledProfile $profile = null)
    {
        $profile ??= ProfileCompiler::commonmark();
        $this->emphasis = new EmphasisProcessor();
        $this->tape = new HtmlInlineRenderer();
        $this->policy = HtmlPolicy::safe();

        foreach ($profile->inlineConstructs() as $construct) {
            if ($construct instanceof ContentScannedInlineConstruct) {
                continue;
            }

            foreach (str_split($construct->triggerBytes()) as $byte) {
                $this->dispatch[\ord($byte)][] = $construct;
            }
        }

        $this->scanned = $profile->contentScannedConstructs;
        $this->specials = $profile->inlineSpecialBytes;
        $this->strikethrough = $profile->strikethrough;
        $this->tagFilter = $profile->tagFilter;
        $this->customRenderers = $profile->htmlInlineRenderers;
        $this->inlineLinks = $profile->inlineLinks;
        $this->htmlLinkDecorators = $profile->htmlLinkDecorators;
        $this->linkDestinationRewriter = $profile->linkDestinationRewriter;
        $customKindNames = [];
        foreach ($profile->inlineLinks as $kind => $_link) {
            $customKindNames[$kind] = $profile->nodeKinds->get($kind)->name;
        }
        $this->customKindNames = $customKindNames;
        $this->content = InlineContent::none();
        $this->references = new ReferenceMap();
    }

    public function useDocumentRenderPlan(?DocumentRenderPlan $plan): void
    {
        $this->documentRenderPlan = $plan;
        $this->tape->useDocumentRenderPlan($plan);
    }

    public function renderBlockInlines(HtmlRenderSource $source, int $blockOrdinal, bool $hardBreaks, HtmlPolicy $policy): string
    {
        $this->policy = $policy;

        if (null !== $source->inlineCountBudget()) {
            return $this->tape->renderBlockInlines($source, $blockOrdinal, $hardBreaks, $policy);
        }

        if (Instrumentation::$timing) {
            Instrumentation::enter('inline-build');
        }

        $inlineSource = $source->inlineSourceView($blockOrdinal);

        if (Instrumentation::$timing) {
            Instrumentation::leave('inline-build');
            Instrumentation::enter('plain-scan');
        }

        $plain = PlainInlineText::renderSource($inlineSource, $source->compiledProfile());

        if (Instrumentation::$timing) {
            Instrumentation::leave('plain-scan');
        }

        if (null !== $plain) {
            return $plain;
        }

        if (Instrumentation::$timing) {
            Instrumentation::enter('inline-build');
        }

        $content = InlineContent::fromPairs($inlineSource->buffer->bytes, $inlineSource->pairs);

        if (Instrumentation::$timing) {
            Instrumentation::leave('inline-build');
        }

        $depth = Instrumentation::$timing ? Instrumentation::regionDepth() : 0;

        try {
            return $this->renderContent($content, $inlineSource->buffer->bytes, $source->referenceMap(), $hardBreaks, true);
        } catch (FusedInlineFallback $fallback) {
            $this->recordFallback($fallback, $depth);

            return $this->tape->renderBlockInlines($source, $blockOrdinal, $hardBreaks, $this->policy);
        }
    }

    public function renderHeadingInlines(HtmlRenderSource $source, int $blockOrdinal, bool $hardBreaks, HtmlPolicy $policy): string
    {
        return $this->tape->renderBlockInlines($source, $blockOrdinal, $hardBreaks, $policy);
    }

    public function renderMarkdown(InlineMarkdownRenderSource $source, string $markdown, HtmlPolicy $policy): string
    {
        $this->policy = $policy;

        if (null !== $source->inlineCountBudget()) {
            return $this->tape->renderMarkdown($source, $markdown, $policy);
        }

        if (Instrumentation::$timing) {
            Instrumentation::enter('inline-build');
        }

        $inlineSource = $source->inlineMarkdownSourceView($markdown);

        if (Instrumentation::$timing) {
            Instrumentation::leave('inline-build');
            Instrumentation::enter('plain-scan');
        }

        $plain = PlainInlineText::renderSource($inlineSource, $source->compiledProfile());

        if (Instrumentation::$timing) {
            Instrumentation::leave('plain-scan');
        }

        if (null !== $plain) {
            return $plain;
        }

        if (Instrumentation::$timing) {
            Instrumentation::enter('inline-build');
        }

        $content = InlineContent::fromPairs($inlineSource->buffer->bytes, $inlineSource->pairs);

        if (Instrumentation::$timing) {
            Instrumentation::leave('inline-build');
        }

        $depth = Instrumentation::$timing ? Instrumentation::regionDepth() : 0;

        try {
            return $this->renderContent(
                $content,
                $inlineSource->buffer->bytes,
                $source->referenceMap(),
                true,
                $source->inlineMarkdownRangesAreOriginal(),
            );
        } catch (FusedInlineFallback $fallback) {
            $this->recordFallback($fallback, $depth);

            return $this->tape->renderMarkdown($source, $markdown, $this->policy);
        }
    }

    public function content(): InlineContent
    {
        return $this->content;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * Emission entry point for the registered inline constructs: instead
     * of appending a tape node, the node's rendered HTML joins the
     * current segment (and its plain-text form joins the alt stream when
     * an image bracket is open).
     */
    public function emit(int $kind, int $end, int $flags = 0, ?string $payload = null): int
    {
        $this->flushTextRun();
        $offset = $this->offset;

        switch ($kind) {
            case InlineKind::TEXT:
                $run = $payload ?? substr($this->text, $offset, $end - $offset);

                if ('' !== $run) {
                    $this->current .= HtmlEscaper::text($run);

                    if ($this->altTracking) {
                        $this->altCurrent .= $run;
                    }
                }

                break;

            case InlineKind::CODE_SPAN:
                $code = $payload ?? substr($this->text, $offset, $end - $offset);
                $this->current .= '<code>'.HtmlEscaper::text($code).'</code>';

                if ($this->altTracking) {
                    $this->altCurrent .= $code;
                }

                break;

            case InlineKind::AUTOLINK:
                $label = substr($this->text, $offset, $end - $offset);
                $destination = null === $this->linkDestinationRewriter
                    ? ($payload ?? '')
                    : $this->rewriteDestination(
                        $this->linkDestinationRewriter,
                        'autolink',
                        $payload ?? '',
                        $offset,
                        $end,
                        $label,
                    );
                $rendered = '<a href="'.HtmlEscaper::attribute($this->policyUrl($destination)).'">'.HtmlEscaper::text($label).'</a>';

                if (null !== $this->htmlLinkDecorators) {
                    $startOffset = $this->content->sourceOffset($offset);
                    $endOffset = $this->content->sourceOffset($end);
                    $rendered = $this->htmlLinkDecorators->decorate(new HtmlNodeOutputContext(
                        'autolink',
                        $this->rangesAreOriginal ? new SourceRange($startOffset, $endOffset) : null,
                        $label,
                        $this->policy,
                        ['destination' => $destination],
                    ), $rendered);
                }

                $this->current .= $rendered;

                if ($this->altTracking) {
                    $this->altCurrent .= $label;
                }

                break;

            case InlineKind::HTML_INLINE:
                $html = $payload ?? substr($this->text, $offset, $end - $offset);
                $this->current .= $this->rawHtml($html);

                if ($this->altTracking) {
                    // The tape renderer's alt flattening reads the original
                    // source slice for raw HTML; mirror it byte for byte.
                    $sourceStart = $this->content->sourceOffset($offset);
                    $this->altCurrent .= substr($this->sourceBytes, $sourceStart, $this->content->sourceOffset($end) - $sourceStart);
                }

                break;

            case InlineKind::SOFT_BREAK:
                $this->current .= "\n";

                if ($this->altTracking) {
                    $this->altCurrent .= "\n";
                }

                break;

            case InlineKind::HARD_BREAK:
                $this->current .= $this->hardBreaks ? "<br />\n" : "\n";

                if ($this->altTracking) {
                    $this->altCurrent .= "\n";
                }

                break;

            default:
                throw new RenderException(\sprintf('No fused HTML emission for inline kind %d.', $kind));
        }

        $this->offset = $end;
        $this->textStart = $end;

        return 0;
    }

    public function emitExtension(int $kind, int $end, InlineNode $node): void
    {
        $this->flushTextRun();

        $renderer = $this->customRenderers[$kind]
            ?? throw new RenderException(\sprintf('No HTML renderer for custom inline kind %d.', $kind));

        if ($renderer instanceof AccumulatingHtmlInlineRenderer) {
            throw new FusedInlineFallback('accumulating-inline');
        }

        if ((isset($this->inlineLinks[$kind]) || $renderer instanceof LinkLikeInlineRenderer) && $this->hasOpenLinkBracket()) {
            throw new FusedInlineFallback('link-like-inside-link');
        }

        $source = substr($this->text, $this->offset, $end - $this->offset);
        $startOffset = $this->content->sourceOffset($this->offset);
        $endOffset = $this->content->sourceOffset($end);
        ++Instrumentation::$extensionInlineRendererLookups;
        $context = new HtmlInlineOutputContext(
            $node,
            $this->rangesAreOriginal ? new SourceRange($startOffset, $endOffset) : null,
            $source,
            $this->policy,
        );
        $rendered = $renderer instanceof PlannedHtmlInlineRenderer && null !== $this->documentRenderPlan
            ? $renderer->renderWithPlan($context, $this->documentRenderPlan)
            : $renderer->render($context);
        $link = $this->inlineLinks[$kind] ?? null;
        $linkAttributes = null;

        if (null !== $link && (null !== $this->linkDestinationRewriter || null !== $this->htmlLinkDecorators)) {
            $linkAttributes = $link->attributes($node);
            if (null !== $this->linkDestinationRewriter) {
                $linkAttributes['destination'] = $this->rewriteDestination(
                    $this->linkDestinationRewriter,
                    $this->customKindNames[$kind],
                    $linkAttributes['destination'],
                    $this->offset,
                    $end,
                    $source,
                );
                $rendered = HtmlUrlAttributeRewriter::first(
                    $rendered,
                    'a',
                    'href',
                    HtmlEscaper::attribute($this->policyUrl($linkAttributes['destination'])),
                );
            }
        }

        if (null !== $link && null !== $this->htmlLinkDecorators) {
            $rendered = $this->htmlLinkDecorators->decorate(new HtmlNodeOutputContext(
                $this->customKindNames[$kind],
                $this->rangesAreOriginal ? new SourceRange($startOffset, $endOffset) : null,
                $source,
                $this->policy,
                $linkAttributes ?? $link->attributes($node),
            ), $rendered);
        }

        $this->current .= $rendered;

        if ($this->altTracking) {
            $this->altCurrent .= $node->text;
        }

        $this->offset = $end;
        $this->textStart = $end;
    }

    private function hasOpenLinkBracket(): bool
    {
        foreach ($this->brackets as $bracket) {
            if (!$bracket->image && $bracket->active) {
                return true;
            }
        }

        return false;
    }

    /**
     * Applies one matched emphasis pair to the segment stream: the open
     * tag prepends before earlier open tags on the opener's slot, the
     * close tag appends after earlier close tags on the closer's slot,
     * and both remnants shrink by $use characters.
     */
    public function wrap(Delimiter $opener, Delimiter $closer, int $use): void
    {
        $openSlot = $opener->node;
        $closeSlot = $closer->node;

        if ('~' === $opener->char) {
            $openTag = '<del>';
            $closeTag = '</del>';
        } elseif (2 === $use) {
            if ($this->strikethrough) {
                $this->guardNestedStrong($openSlot, $closeSlot);
            }

            $openTag = '<strong>';
            $closeTag = '</strong>';
        } else {
            $openTag = '<em>';
            $closeTag = '</em>';
        }

        $opener->length -= $use;
        $this->slotLength[$openSlot] = $opener->length;
        $this->slotOpen[$openSlot] = $openTag.($this->slotOpen[$openSlot] ?? '');
        $this->slotOpenCount[$openSlot] = ($this->slotOpenCount[$openSlot] ?? 0) + 1;

        $closer->length -= $use;
        $this->slotLength[$closeSlot] = $closer->length;
        $this->slotClose[$closeSlot] = ($this->slotClose[$closeSlot] ?? '').$closeTag;
        $this->slotCloseCount[$closeSlot] = ($this->slotCloseCount[$closeSlot] ?? 0) + 1;
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

    private function renderContent(
        InlineContent $content,
        string $sourceBytes,
        ReferenceMap $references,
        bool $hardBreaks,
        bool $rangesAreOriginal,
    ): string {
        ++Instrumentation::$fusedInlineRenders;

        $this->content = $content;
        $this->text = $text = $content->text;
        $length = \strlen($text);
        $this->sourceBytes = $sourceBytes;
        $this->references = $references;
        $this->hardBreaks = $hardBreaks;
        $this->rangesAreOriginal = $rangesAreOriginal;
        $this->parts = [];
        $this->current = '';
        $this->slotChar = [];
        $this->slotLength = [];
        $this->slotOpen = [];
        $this->slotClose = [];
        $this->slotOpenCount = [];
        $this->slotCloseCount = [];
        $this->delimiters = [];
        $this->brackets = [];
        $this->imageDepth = 0;
        $this->altTracking = false;
        $this->altParts = [];
        $this->altCurrent = '';
        $this->strongOpens = [];

        if (Instrumentation::$timing) {
            Instrumentation::enter('inline-scan');
        }

        // The block's content is trimmed as a whole: leading and trailing
        // whitespace is layout (the final flush handles the tail).
        $skip = strspn($text, " \t");
        $this->offset = $skip;
        $this->textStart = $skip;

        // Where a content-scanned construct could start next. Recomputed
        // only once the cursor has passed it, so a construct that consumed
        // a region (a code span, raw HTML) moves the search past its
        // interior and candidates inside it never come back.
        $candidate = $this->scannedCandidate($text, $skip);

        while ($this->offset < $length) {
            $offset = $this->offset;

            if ($candidate >= 0 && $candidate < $offset) {
                $candidate = $this->scannedCandidate($text, $offset);
            }

            // The jump stops at the candidate, so declining one costs the
            // bytes up to the next candidate rather than a fresh scan of
            // the rest of the block.
            $limit = $candidate >= $offset ? $candidate - $offset : $length - $offset;
            $jump = strcspn($text, $this->specials, $offset, $limit);

            if ($jump === $limit && $candidate >= $offset) {
                $this->offset = $candidate;
                $matched = false;

                foreach ($this->scanned as $construct) {
                    if ($construct->tryParse($this)) {
                        $matched = true;

                        break;
                    }
                }

                if (!$matched) {
                    ++$this->offset;
                }

                continue;
            }

            if ($jump > 0) {
                $this->offset = $offset + $jump;

                continue;
            }

            if ("\n" === $text[$offset]) {
                $joint = $content->jointAt($offset);
                $this->flushTextRun($content->breakChops[$joint]);
                $this->emit($content->breakKinds[$joint], $offset + 1);
                $this->offset += strspn($text, " \t", $offset + 1, $length - $offset - 1);
                $this->textStart = $this->offset;

                continue;
            }

            $byte = $text[$offset];

            if ('[' === $byte) {
                $this->openBracket(false, $offset + 1);

                continue;
            }

            if ('!' === $byte) {
                if ($offset + 1 < $length && '[' === $text[$offset + 1]) {
                    $this->openBracket(true, $offset + 2);
                } else {
                    ++$this->offset;
                }

                continue;
            }

            if (']' === $byte) {
                $this->flushTextRun();

                if (Instrumentation::$timing) {
                    Instrumentation::enter('link-resolve');
                }

                $next = $this->closeBracket($offset);

                if (Instrumentation::$timing) {
                    Instrumentation::leave('link-resolve');
                }

                if (null === $next) {
                    ++$this->offset;
                } else {
                    $this->offset = $next;
                    $this->textStart = $next;
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

                $slot = $this->reserveDelimiterSlot($byte, $run, $offset);
                $this->delimiters[] = new Delimiter($slot, $byte, $run, $canOpen, $canClose);

                continue;
            }

            if ($this->strikethrough && '~' === $byte) {
                $run = strspn($text, '~', $offset);
                $slot = $this->reserveDelimiterSlot('~', $run, $offset);
                $this->delimiters[] = new Delimiter($slot, '~', $run, true, true);

                continue;
            }

            $claimed = false;

            foreach ($this->dispatch[\ord($byte)] ?? [] as $construct) {
                if ($construct->tryParse($this)) {
                    $claimed = true;

                    break;
                }
            }

            if (!$claimed) {
                ++$this->offset;
            }
        }

        // Final flush strips block-final whitespace: it is layout, and no
        // construct claimed it.
        $this->flushTextRun(strspn(strrev($text), " \t"));

        if (Instrumentation::$timing) {
            Instrumentation::leave('inline-scan');
            Instrumentation::enter('emphasis');
        }

        $this->emphasis->process($this, $this->delimiters);

        if (Instrumentation::$timing) {
            Instrumentation::leave('emphasis');
        }

        return $this->assemble();
    }

    /**
     * Flushes the pending literal run into the current segment. $trim
     * strips that many bytes from the run's end (break whitespace, the
     * break backslash); the stripped bytes never reach the output.
     */
    private function flushTextRun(int $trim = 0): void
    {
        $end = max($this->textStart, $this->offset - $trim);

        if ($end > $this->textStart) {
            $run = substr($this->text, $this->textStart, $end - $this->textStart);
            $this->current .= HtmlEscaper::text($run);

            if ($this->altTracking) {
                $this->altCurrent .= $run;
            }
        }

        $this->textStart = $this->offset;
    }

    /**
     * Pushes the current literal segment and reserves the next part with
     * $literal, returning its index. $alt overrides the part's plain-text
     * form when it differs from the literal.
     */
    private function reservePart(string $literal, ?string $alt = null): int
    {
        $this->parts[] = $this->current;
        $index = \count($this->parts);
        $this->parts[] = $literal;
        $this->current = '';

        if ($this->altTracking) {
            $this->altParts[$index - 1] = $this->altCurrent;
            $this->altParts[$index] = $alt ?? $literal;
            $this->altCurrent = '';
        }

        return $index;
    }

    private function reserveDelimiterSlot(string $char, int $run, int $offset): int
    {
        $this->flushTextRun();
        // The part value is never read: assemble() recomposes every slot
        // and image blanking clears slots inside a resolved label.
        $index = $this->reservePart('');
        $this->slotChar[$index] = $char;
        $this->slotLength[$index] = $run;
        $this->offset = $offset + $run;
        $this->textStart = $this->offset;

        return $index;
    }

    private function openBracket(bool $image, int $contentOffset): void
    {
        $this->flushTextRun();
        $index = $this->reservePart($image ? '![' : '[');
        $this->brackets[] = new Bracket($index, $image, \count($this->delimiters), $contentOffset, ParseTape::NONE);
        $this->offset = $contentOffset;
        $this->textStart = $contentOffset;

        if ($image) {
            ++$this->imageDepth;
            $this->altTracking = true;
        }
    }

    /**
     * Handles a "]" at $offset: the same bracket-stack and reference
     * logic as LinkResolver, applied to segments. Returns the content
     * offset after the consumed construct, or null when the bracket does
     * not form a link (the "]" stays literal; the failed opener is
     * dropped).
     */
    private function closeBracket(int $offset): ?int
    {
        $bracket = array_pop($this->brackets);

        if (null === $bracket) {
            return null;
        }

        if (!$bracket->active) {
            $this->dropImageBracket($bracket);

            return null;
        }

        $match = LinkResolver::match($this->text, $bracket->contentOffset, $offset, $this->references);

        if (null === $match) {
            $this->dropImageBracket($bracket);

            return null;
        }

        if (!$bracket->image && null !== $this->htmlLinkDecorators) {
            throw new FusedInlineFallback('link-decoration');
        }

        [$destination, $title, $end] = $match;

        // Emphasis inside the label resolves first, bounded below by the
        // delimiters that existed when the bracket opened.
        if (Instrumentation::$timing) {
            Instrumentation::enter('emphasis');
        }

        $this->emphasis->process($this, $this->delimiters, $bracket->delimiterIndex);

        if (Instrumentation::$timing) {
            Instrumentation::leave('emphasis');
        }

        array_splice($this->delimiters, $bracket->delimiterIndex);

        $sourceStart = $bracket->contentOffset - ($bracket->image ? 2 : 1);
        $destination = Href::encode(Href::resolve($destination));
        $destination = null === $this->linkDestinationRewriter
            ? $destination
            : $this->rewriteDestination(
                $this->linkDestinationRewriter,
                $bracket->image ? 'image' : 'link',
                $destination,
                $sourceStart,
                $end,
                substr($this->text, $sourceStart, $end - $sourceStart),
            );
        $href = $this->policyUrl($destination);
        $title = Href::resolve($title);
        $attribute = '' === $title ? '' : ' title="'.HtmlEscaper::attribute($title).'"';

        if ($bracket->image) {
            $this->patchImage($bracket, $href, $attribute);

            return $end;
        }

        $this->parts[$bracket->node] = '<a href="'.HtmlEscaper::attribute($href).'"'.$attribute.'>';
        $this->reservePart('</a>', '');

        if ($this->altTracking) {
            $this->altParts[$bracket->node] = '';
        }

        // No links inside links: earlier link openers deactivate.
        foreach ($this->brackets as $earlier) {
            if (!$earlier->image) {
                $earlier->active = false;
            }
        }

        return $end;
    }

    private function dropImageBracket(Bracket $bracket): void
    {
        if ($bracket->image) {
            --$this->imageDepth;

            if (0 === $this->imageDepth) {
                // Tracking ends: drop the pending alt run, or it would leak
                // into the next image's flattening.
                $this->altTracking = false;
                $this->altCurrent = '';
            }
        }
    }

    /**
     * Replaces a resolved image's label segments with one complete img
     * tag: the alt text is the plain-text flattening collected since the
     * bracket opened (delimiter slots contribute their post-emphasis
     * remnants), and every label segment is blanked.
     */
    private function patchImage(Bracket $bracket, string $href, string $attribute): void
    {
        $alt = '';
        $count = \count($this->parts);

        for ($i = $bracket->node + 1; $i < $count; ++$i) {
            if (isset($this->slotChar[$i])) {
                $alt .= str_repeat($this->slotChar[$i], $this->slotLength[$i]);
                unset($this->slotChar[$i], $this->slotLength[$i], $this->slotOpen[$i], $this->slotClose[$i], $this->slotOpenCount[$i], $this->slotCloseCount[$i]);
            } else {
                $alt .= $this->altParts[$i] ?? '';
            }

            $this->parts[$i] = '';
            $this->altParts[$i] = '';
        }

        $alt .= $this->altCurrent;
        $this->current = '';
        $this->altCurrent = '';

        $this->parts[$bracket->node] = '<img src="'.HtmlEscaper::attribute($href).'" alt="'.HtmlEscaper::attribute($alt).'"'.$attribute.' />';

        --$this->imageDepth;
        $this->altTracking = $this->imageDepth > 0;

        if ($this->altTracking) {
            $this->altParts[$bracket->node] = $alt;
        }
    }

    /**
     * Aborts the fused render when a STRONG wrap's span contains another
     * STRONG's open tag: the tape renderer suppresses directly nested
     * strong tags (GFM behavior) through recursion the flat stream cannot
     * mirror. The check over-approximates (an em, del, or link between
     * the two strongs shields the inner one in tree terms) but only ever
     * costs a fallback, never a wrong byte.
     */
    private function guardNestedStrong(int $openSlot, int $closeSlot): void
    {
        $openPos = ($openSlot << self::PART_SHIFT) - ($this->slotOpenCount[$openSlot] ?? 0) - 1;
        $closePos = ($closeSlot << self::PART_SHIFT) + ($this->slotCloseCount[$closeSlot] ?? 0) + 1;

        $count = \count($this->strongOpens);
        $low = 0;
        $high = $count;

        while ($low < $high) {
            $mid = ($low + $high) >> 1;

            if ($this->strongOpens[$mid] <= $openPos) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        if ($low < $count && $this->strongOpens[$low] < $closePos) {
            throw new FusedInlineFallback('nested-strong');
        }

        if ($low === $count) {
            $this->strongOpens[] = $openPos;

            return;
        }

        array_splice($this->strongOpens, $low, 0, [$openPos]);
    }

    private function assemble(): string
    {
        foreach ($this->slotChar as $index => $char) {
            $length = $this->slotLength[$index];
            $this->parts[$index] = ($this->slotClose[$index] ?? '')
                .($length > 0 ? str_repeat($char, $length) : '')
                .($this->slotOpen[$index] ?? '');
        }

        return implode('', $this->parts).$this->current;
    }

    /**
     * Applies the URL policy to an emitted destination: a disallowed
     * scheme empties the attribute value and keeps the element.
     */
    private function policyUrl(string $href): string
    {
        return $this->policy->filtersUrls && !$this->policy->allowsUrl($href) ? '' : $href;
    }

    private function rewriteDestination(
        CompiledLinkDestinationRewriter $rewriter,
        string $kind,
        string $destination,
        int $start,
        int $end,
        string $source,
    ): string {
        $destination = Href::encode($destination);
        $range = $this->rangesAreOriginal
            ? new SourceRange($this->content->sourceOffset($start), $this->content->sourceOffset($end))
            : null;

        return Href::encode($rewriter->rewrite(
            $kind,
            $destination,
            $range,
            $source,
        ));
    }

    /**
     * Applies the raw HTML policy to one inline raw HTML slice.
     */
    private function rawHtml(string $html): string
    {
        return match ($this->policy->rawHtml) {
            RawHtmlPolicy::Strip => '',
            RawHtmlPolicy::Escape => HtmlEscaper::text($html),
            RawHtmlPolicy::Allow => $this->policy->sanitizesHtml() || !$this->tagFilter
                ? $html
                : HtmlInlineRenderer::filterRawTags($html),
        };
    }

    private function recordFallback(FusedInlineFallback $fallback, int $regionDepth): void
    {
        ++Instrumentation::$fusedInlineFallbacks;
        Instrumentation::$fusedFallbackReasons[$fallback->reason] = (Instrumentation::$fusedFallbackReasons[$fallback->reason] ?? 0) + 1;

        if (Instrumentation::$timing) {
            Instrumentation::unwindRegions($regionDepth);
        }
    }
}
