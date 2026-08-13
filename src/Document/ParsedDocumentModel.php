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

namespace Alto\Markdown\Document;

use Alto\Markdown\Document\Handle\BlockNodeHandle;
use Alto\Markdown\Document\Handle\CodeBlockHandle;
use Alto\Markdown\Document\Handle\FrontMatterHandle;
use Alto\Markdown\Document\Handle\HeadingHandle;
use Alto\Markdown\Document\Handle\ImageHandle;
use Alto\Markdown\Document\Handle\LinkHandle;
use Alto\Markdown\Document\Handle\TapeNodeHandle;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\MarkdownInternalException;
use Alto\Markdown\Exception\StaleHandleException;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Extension\DescriptionList\DescriptionListExtension;
use Alto\Markdown\Extension\FrontMatter\FrontMatterExtension;
use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Operation\BlockInsertPosition;
use Alto\Markdown\Operation\EditJournal;
use Alto\Markdown\Parser\Block\BlockContentReader;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\CaseFold;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Inline\InlineMarkdownInput;
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\Inline\InlineSourceView;
use Alto\Markdown\Parser\Inline\InlineTapeView;
use Alto\Markdown\Parser\InlineCache;
use Alto\Markdown\Parser\InlineCountBudget;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParsedSyntax;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReadOnlyParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use Alto\Markdown\Parser\SyntaxParser;
use Alto\Markdown\Profile\CompiledProfile;
use Alto\Markdown\Profile\Feature;
use Alto\Markdown\Render\HtmlRenderSource;
use Alto\Markdown\Render\MarkdownRenderer;
use Alto\Markdown\Render\TableCellHtmlCache;
use Alto\Markdown\Source\LineEnding;
use Alto\Markdown\Source\SourceDocument;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ParsedDocumentModel implements DocumentModel, HtmlRenderSource
{
    /**
     * @var array<int, string>
     */
    private array $inlineMarkdownOverrides = [];

    /**
     * @var array<int, array{language: string|null, code: string}>
     */
    private array $codeBlockOverrides = [];

    /**
     * @var array<int, string>
     */
    private array $frontMatterContentOverrides = [];

    /**
     * @var array<int, string>
     */
    private array $generatedBlockMarkdown = [];

    /**
     * @var array<int, true>
     */
    private array $modifiedMarkdownOrdinals = [];

    /**
     * @var array<int, true>
     */
    private array $removedOrdinals = [];

    /**
     * Per-(ordinal, generation) decode of a table block's cell strings. The
     * decode splits the row payload and slices every cell from the buffer; it
     * is stable while the block's generation holds, so warm renders reuse it
     * instead of re-deriving cell strings each pass.
     *
     * @var array<int, array{int, array{list<string>, list<string>, list<list<string>>}}>
     */
    private array $tablePartsCache = [];

    private ParsedSyntax $syntax;

    private ?InlineCache $inlineCache = null;

    private ParseOptions $parseOptions;

    private ?InlineCountBudget $inlineCountBudget = null;

    private ?TableCellHtmlCache $tableCellHtmlCache = null;

    /**
     * @var array<int, string>
     */
    private array $headingSlugs = [];

    private int $headingSlugGeneration = -1;

    private ?EditJournal $journal = null;

    private SourceBuffer $buffer;

    private ParseTape $tape;

    /**
     * Document-wide revision counter, exposed as DocumentModel::generation().
     * It starts at 0 for a freshly parsed document and only ever increases: one
     * step per mutation, one step per rebase. A block's NodeId generation is the
     * revision at which that block was last built or rebuilt.
     */
    private int $generation = 0;

    /**
     * Revision of the current tape's parse. A tape slot stores its stamp
     * relative to this floor, so a block's document-wide generation is
     * $generationFloor + $tape->generation($ordinal), and a freshly parsed slot
     * (tape stamp 0) reads back as the revision of its parse.
     *
     * A rebase raises the floor above every generation the previous tape ever
     * handed out. That is what stops a handle taken before the rebase from
     * matching an ordinal the new tape reused for a different node: ordinals
     * restart from scratch on a reparse, generations do not.
     */
    private int $generationFloor = 0;

    private int $rootOrdinal;

    private CompiledProfile $profile;

    private ReferenceMap $referenceMap;

    private BlockContentReader $blockContent;

    public function __construct(
        ParsedSyntax $syntax,
        private InlineParser $inlineParser,
        private SyntaxParser $syntaxParser,
    ) {
        ++Instrumentation::$documentWorkspaces;
        $this->adoptSyntax($syntax);
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function root(): NodeHandle
    {
        return $this->node($this->rootNodeId());
    }

    public function node(NodeId $id): NodeHandle
    {
        $current = $this->currentNodeId($id->ordinal);
        $kind = $this->nodeKind($current);

        if (FrontMatterExtension::BLOCK_KIND === $kind->name) {
            return new FrontMatterHandle($this, $current);
        }

        return match ($this->tape->kindId($current->ordinal)) {
            BlockKind::ATX_HEADING, BlockKind::SETEXT_HEADING => new HeadingHandle($this, $current),
            BlockKind::FENCED_CODE, BlockKind::INDENTED_CODE => new CodeBlockHandle($this, $current),
            BlockKind::DOCUMENT => new TapeNodeHandle($this, $current),
            default => new BlockNodeHandle($this, $current),
        };
    }

    public function source(): SourceDocument
    {
        return $this->syntax->source();
    }

    public function journal(): EditJournal
    {
        return $this->journal ??= new InMemoryEditJournal();
    }

    public function rebase(string $bytes): void
    {
        $this->adoptRebase($this->prepareRebase($bytes));
    }

    public function prepareRebase(string $bytes): ParsedSyntax
    {
        return $this->syntaxParser->parse($bytes, $this->parseOptions);
    }

    public function adoptRebase(ParsedSyntax $syntax): void
    {
        $this->adoptSyntax($syntax);
        $this->generationFloor = ++$this->generation;
        $this->inlineCache = null;
        $this->tableCellHtmlCache = null;
        $this->tablePartsCache = [];
        $this->inlineMarkdownOverrides = [];
        $this->codeBlockOverrides = [];
        $this->frontMatterContentOverrides = [];
        $this->generatedBlockMarkdown = [];
        $this->modifiedMarkdownOrdinals = [];
        $this->removedOrdinals = [];
        $this->journal?->clear();
    }

    public function nodeKind(NodeId $id): NodeKind
    {
        return $this->profile->nodeKinds->get($this->tape->kindId($id->ordinal));
    }

    public function range(NodeId $id): SourceRange
    {
        return new SourceRange(
            $this->tape->startOffset($id->ordinal),
            $this->tape->endOffset($id->ordinal),
        );
    }

    public function exists(NodeId $id): bool
    {
        if ($id->ordinal < 0 || $id->ordinal >= $this->tape->count()) {
            return false;
        }

        return !isset($this->removedOrdinals[$id->ordinal]) && $this->generationFloor + $this->tape->generation($id->ordinal) === $id->generation;
    }

    public function headingLevel(int $ordinal): int
    {
        return $this->tape->flags($ordinal);
    }

    public function debugBumpGeneration(int $ordinal): void
    {
        ++$this->generation;
        $this->stampGeneration($ordinal);
    }

    public function replaceHeadingMarkdown(NodeId $id, string $markdown): NodeId
    {
        $this->assertLiveKind($id, [BlockKind::ATX_HEADING, BlockKind::SETEXT_HEADING]);
        $this->inlineMarkdownOverrides[$id->ordinal] = $markdown;
        $this->modifiedMarkdownOrdinals[$id->ordinal] = true;
        ++$this->generation;
        $this->stampGeneration($id->ordinal);

        return $this->currentNodeId($id->ordinal);
    }

    public function setCodeBlock(NodeId $id, ?string $language, string $code): NodeId
    {
        $this->assertLiveKind($id, [BlockKind::FENCED_CODE, BlockKind::INDENTED_CODE]);
        $this->codeBlockOverrides[$id->ordinal] = ['language' => $language, 'code' => $code];
        $this->modifiedMarkdownOrdinals[$id->ordinal] = true;
        $this->tape->setKindId($id->ordinal, BlockKind::FENCED_CODE);
        ++$this->generation;
        $this->stampGeneration($id->ordinal);

        return $this->currentNodeId($id->ordinal);
    }

    /**
     * @return list<NodeId>
     */
    public function insertMarkdownBefore(NodeId $target, string $markdown): array
    {
        $this->assertLive($target);

        return $this->insertMarkdownNear($target, $markdown, before: true);
    }

    /**
     * @return list<NodeId>
     */
    public function insertMarkdownAfter(NodeId $target, string $markdown): array
    {
        $this->assertLive($target);

        return $this->insertMarkdownNear($target, $markdown, before: false);
    }

    public function blockInsertionRange(NodeId $target, BlockInsertPosition $position): SourceRange
    {
        $this->assertLive($target);

        if ($this->rootOrdinal !== $this->tape->parentOrdinal($target->ordinal)) {
            throw new InvalidMarkdownArgumentException('Block insertion before or after a handle is limited to top-level blocks.');
        }

        if (
            BlockInsertPosition::Before === $position
            && FrontMatterExtension::BLOCK_KIND === $this->nodeKind($target)->name
        ) {
            throw new InvalidMarkdownArgumentException('Nothing can be inserted before an existing front matter block.');
        }

        $offset = match ($position) {
            BlockInsertPosition::Before => $this->physicalLineStart($this->tape->startOffset($target->ordinal)),
            BlockInsertPosition::After => $this->physicalBlockEnd($target->ordinal),
        };

        return new SourceRange($offset, $offset);
    }

    public function blockSourceRange(NodeId $id): SourceRange
    {
        $this->assertTopLevelBlock($id);
        $range = $this->range($id);

        if ($range->startOffset === $range->endOffset) {
            return $range;
        }

        return new SourceRange(
            $this->physicalLineStart($range->startOffset),
            $this->physicalBlockEnd($id->ordinal),
        );
    }

    public function blockMoveDeletionRange(NodeId $id): SourceRange
    {
        $range = $this->blockSourceRange($id);

        if ($range->startOffset === $range->endOffset) {
            return $range;
        }

        $logicalStart = $this->logicalSourceStart();

        if ($range->startOffset > $logicalStart) {
            $previous = $this->previousLineEndingStart($range->startOffset);

            if (null !== $previous && null !== $this->previousLineEndingStart($previous)) {
                return new SourceRange($previous, $range->endOffset);
            }
        }

        if (
            $range->startOffset === $logicalStart
            && $range->endOffset < $this->buffer->length
            && ("\r" === $this->buffer->bytes[$range->endOffset] || "\n" === $this->buffer->bytes[$range->endOffset])
        ) {
            $nextEnd = $this->lineEndAfter($range->endOffset);

            if ($nextEnd > $range->endOffset) {
                return new SourceRange($range->startOffset, $nextEnd);
            }
        }

        return $range;
    }

    public function blockIsSourceBacked(NodeId $id): bool
    {
        $range = $this->blockSourceRange($id);

        return $range->startOffset < $range->endOffset;
    }

    public function blockMarkdown(NodeId $id): string
    {
        $range = $this->blockSourceRange($id);

        if (
            isset($this->generatedBlockMarkdown[$id->ordinal])
            && !$this->subtreeHasMarkdownModifications($id->ordinal)
        ) {
            return $this->generatedBlockMarkdown[$id->ordinal];
        }

        if (
            $range->startOffset < $range->endOffset
            && !$this->subtreeHasMarkdownOverrides($id->ordinal)
        ) {
            return substr($this->source()->bytes, $range->startOffset, $range->endOffset - $range->startOffset);
        }

        return new MarkdownRenderer()->renderBlock($this, $id->ordinal);
    }

    public function blocksAreAtPosition(NodeId $target, NodeId $anchor, BlockInsertPosition $position): bool
    {
        $this->assertTopLevelBlock($target);
        $this->assertTopLevelBlock($anchor);

        if ($target->ordinal === $anchor->ordinal) {
            return true;
        }

        return match ($position) {
            BlockInsertPosition::Before => $this->tape->nextSiblingOrdinal($target->ordinal) === $anchor->ordinal,
            BlockInsertPosition::After => $this->tape->nextSiblingOrdinal($anchor->ordinal) === $target->ordinal,
        };
    }

    public function isFrontMatterBlock(NodeId $id): bool
    {
        $this->assertLive($id);

        return FrontMatterExtension::BLOCK_KIND === $this->nodeKind($id)->name;
    }

    public function wouldCreateFrontMatterAfterInsertion(
        NodeId $target,
        string $markdown,
        BlockInsertPosition $position,
    ): bool {
        if (!$this->profile->supports(Feature::FrontMatter)) {
            return false;
        }

        $this->assertTopLevelBlock($target);
        $blocks = [];

        foreach ($this->topLevelBlockMarkdown() as $ordinal => $blockMarkdown) {
            if ($ordinal === $target->ordinal && BlockInsertPosition::Before === $position) {
                $blocks[] = $markdown;
            }

            $blocks[] = $blockMarkdown;

            if ($ordinal === $target->ordinal && BlockInsertPosition::After === $position) {
                $blocks[] = $markdown;
            }
        }

        return $this->wouldCreateFrontMatterFromBlocks($blocks);
    }

    public function wouldCreateFrontMatterAfterReplacement(NodeId $target, string $markdown): bool
    {
        if (!$this->profile->supports(Feature::FrontMatter)) {
            return false;
        }

        $this->assertTopLevelBlock($target);
        $blocks = [];

        foreach ($this->topLevelBlockMarkdown() as $ordinal => $blockMarkdown) {
            if ($ordinal !== $target->ordinal) {
                $blocks[] = $blockMarkdown;

                continue;
            }

            if ('' !== $markdown) {
                $blocks[] = $markdown;
            }
        }

        return $this->wouldCreateFrontMatterFromBlocks($blocks);
    }

    public function wouldCreateFrontMatterAfterMove(
        NodeId $target,
        NodeId $anchor,
        BlockInsertPosition $position,
    ): bool {
        if (!$this->profile->supports(Feature::FrontMatter)) {
            return false;
        }

        $this->assertTopLevelBlock($target);
        $this->assertTopLevelBlock($anchor);
        $current = $this->topLevelBlockMarkdown();
        $moved = $current[$target->ordinal];
        unset($current[$target->ordinal]);
        $blocks = [];

        foreach ($current as $ordinal => $blockMarkdown) {
            if ($ordinal === $anchor->ordinal && BlockInsertPosition::Before === $position) {
                $blocks[] = $moved;
            }

            $blocks[] = $blockMarkdown;

            if ($ordinal === $anchor->ordinal && BlockInsertPosition::After === $position) {
                $blocks[] = $moved;
            }
        }

        return $this->wouldCreateFrontMatterFromBlocks($blocks);
    }

    /**
     * @return array<int, string>
     */
    private function topLevelBlockMarkdown(): array
    {
        $blocks = [];
        $ordinal = $this->tape->firstChildOrdinal($this->rootOrdinal);

        while (ParseTape::NONE !== $ordinal) {
            $blocks[$ordinal] = $this->blockMarkdown($this->currentNodeId($ordinal));
            $ordinal = $this->tape->nextSiblingOrdinal($ordinal);
        }

        return $blocks;
    }

    /**
     * @param list<string> $blocks
     */
    private function wouldCreateFrontMatterFromBlocks(array $blocks): bool
    {
        if (ParseTape::NONE !== $this->frontMatterOrdinal()) {
            return false;
        }

        $eol = $this->source()->dominantEol->value;
        $blocks = array_values(array_filter(
            array_map(static fn(string $block): string => trim($block, "\r\n"), $blocks),
            static fn(string $block): bool => '' !== $block,
        ));
        $candidate = ($this->source()->hasBom ? "\xEF\xBB\xBF" : '') . implode($eol . $eol, $blocks);
        $parsed = new self(
            $this->syntaxParser->parse($candidate, $this->parseOptions),
            $this->inlineParser,
            $this->syntaxParser,
        );

        return ParseTape::NONE !== $parsed->frontMatterOrdinal();
    }

    /**
     * @return list<NodeId>
     */
    public function replaceBlockWithMarkdown(NodeId $target, string $markdown): array
    {
        $inserted = $this->insertMarkdownBefore($target, $markdown);
        $this->removeBlock($target);

        return $inserted;
    }

    public function removeBlock(NodeId $id): void
    {
        $this->assertLive($id);
        ++$this->generation;
        $this->unlinkOrdinal($id->ordinal);
        $this->markSubtreeRemoved($id->ordinal);
    }

    public function moveBlockBefore(NodeId $target, NodeId $anchor): void
    {
        $this->moveBlock($target, $anchor, before: true);
    }

    public function moveBlockAfter(NodeId $target, NodeId $anchor): void
    {
        $this->moveBlock($target, $anchor, before: false);
    }

    public function prependMarkdownToSection(NodeId $headingId, int $endOffset, string $markdown): void
    {
        $this->assertLiveKind($headingId, [BlockKind::ATX_HEADING, BlockKind::SETEXT_HEADING]);
        $this->insertMarkdownAfter($headingId, $markdown);
    }

    public function appendMarkdownToSection(NodeId $headingId, int $endOffset, string $markdown): void
    {
        $this->assertLiveKind($headingId, [BlockKind::ATX_HEADING, BlockKind::SETEXT_HEADING]);
        $last = $this->lastSectionBodyOrdinal($headingId->ordinal, $endOffset);

        if (ParseTape::NONE === $last) {
            $this->insertMarkdownAfter($headingId, $markdown);

            return;
        }

        $this->insertMarkdownAfter($this->currentNodeId($last), $markdown);
    }

    public function replaceSectionBody(NodeId $headingId, int $endOffset, string $markdown): void
    {
        $this->assertLiveKind($headingId, [BlockKind::ATX_HEADING, BlockKind::SETEXT_HEADING]);
        $this->removeSectionBody($headingId, $endOffset);
        $this->insertMarkdownAfter($headingId, $markdown);
    }

    public function removeSection(NodeId $headingId, int $endOffset): void
    {
        $this->assertLiveKind($headingId, [BlockKind::ATX_HEADING, BlockKind::SETEXT_HEADING]);

        foreach ($this->sectionOrdinals($headingId->ordinal, $endOffset) as $ordinal) {
            if ($this->exists($this->currentNodeId($ordinal))) {
                $this->removeBlock($this->currentNodeId($ordinal));
            }
        }
    }

    /**
     * @return list<NodeId>
     */
    public function appendMarkdownToDocument(string $markdown): array
    {
        $detached = $this->parseDetached($markdown);
        $sourceChild = $detached->firstChildOrdinal($detached->rootOrdinal);

        if (ParseTape::NONE === $sourceChild) {
            return [];
        }

        ++$this->generation;
        $insertionOffset = $this->tape->endOffset($this->rootOrdinal);
        $inserted = $this->importRootChildren($detached, $sourceChild, $this->rootOrdinal, $insertionOffset);
        $firstInserted = $inserted[0]->ordinal;
        $last = $this->lastChildOrdinal($this->rootOrdinal);

        if (ParseTape::NONE === $last) {
            $this->tape->linkFirstChild($this->rootOrdinal, $firstInserted);

            return $inserted;
        }

        $this->tape->linkNextSibling($last, $firstInserted);

        return $inserted;
    }

    public function plainText(int $ordinal): string
    {
        $inlineTape = $this->inlineTape($ordinal);

        return PlainText::fromInlineTape($this->inlineSourceBuffer($ordinal), $inlineTape, 0);
    }

    /**
     * @return iterable<HeadingHandle>
     */
    public function headings(?int $level): iterable
    {
        foreach ($this->headingOrdinals() as $ordinal) {
            if (null !== $level && $this->tape->flags($ordinal) !== $level) {
                continue;
            }

            yield new HeadingHandle($this, $this->currentNodeId($ordinal));
        }
    }

    /**
     * @return iterable<array{NodeId, int}>
     */
    public function sections(string $title): iterable
    {
        $needle = $this->normalizeTitle($title);
        $headings = $this->headingOrdinals();
        $count = \count($headings);

        for ($index = 0; $index < $count; ++$index) {
            $ordinal = $headings[$index];

            if ($needle !== $this->normalizeTitle($this->plainText($ordinal))) {
                continue;
            }

            yield [$this->currentNodeId($ordinal), $this->sectionEndOffset($headings, $index)];
        }
    }

    /**
     * @return iterable<LinkHandle>
     */
    public function links(): iterable
    {
        foreach ($this->inlineHandles(InlineKind::LINK) as [$blockId, $inlineOrdinal]) {
            yield new LinkHandle($this, $blockId, $inlineOrdinal);
        }
    }

    /**
     * @return iterable<ImageHandle>
     */
    public function images(): iterable
    {
        foreach ($this->inlineHandles(InlineKind::IMAGE) as [$blockId, $inlineOrdinal]) {
            yield new ImageHandle($this, $blockId, $inlineOrdinal);
        }
    }

    /**
     * Takes an immutable snapshot before a batch destination rewrite. The
     * caller can build and validate the complete plan without holding live
     * handles across document generations.
     *
     * @return list<InlineLinkTarget>
     *
     * @internal
     */
    public function inlineLinkTargets(): array
    {
        $targets = [];

        foreach ($this->descendants($this->rootOrdinal) as $blockOrdinal) {
            if (!$this->hasInlineContent($blockOrdinal)) {
                continue;
            }

            $inlineTape = $this->inlineTape($blockOrdinal);
            $buffer = $this->inlineSourceBuffer($blockOrdinal);

            for ($inlineOrdinal = 1; $inlineOrdinal < $inlineTape->count(); ++$inlineOrdinal) {
                $kind = $inlineTape->kindId($inlineOrdinal);
                if (InlineKind::LINK !== $kind && InlineKind::IMAGE !== $kind) {
                    continue;
                }

                $range = new SourceRange(
                    $inlineTape->startOffset($inlineOrdinal),
                    $inlineTape->endOffset($inlineOrdinal),
                );
                [$destination, $title] = explode(
                    "\x00",
                    ($inlineTape->payload($inlineOrdinal) ?? "\x00") . "\x00",
                    3,
                );
                $labelEnd = $inlineTape->flags($inlineOrdinal);

                $targets[] = new InlineLinkTarget(
                    $blockOrdinal,
                    $inlineOrdinal,
                    $kind,
                    InlineKind::IMAGE === $kind ? 'image' : 'link',
                    $destination,
                    '' === $title ? null : $title,
                    $range,
                    $buffer->substring($range->startOffset, $range->endOffset),
                    '(' === ($buffer->bytes[$labelEnd + 1] ?? ''),
                );
            }
        }

        return $targets;
    }

    /**
     * @return iterable<CodeBlockHandle>
     */
    public function codeBlocks(?string $language): iterable
    {
        foreach ($this->descendants($this->rootOrdinal) as $ordinal) {
            $kind = $this->tape->kindId($ordinal);

            if (BlockKind::FENCED_CODE !== $kind && BlockKind::INDENTED_CODE !== $kind) {
                continue;
            }

            if (null !== $language && $language !== $this->codeBlockLanguage($ordinal)) {
                continue;
            }

            yield new CodeBlockHandle($this, $this->currentNodeId($ordinal));
        }
    }

    public function inlineRange(int $blockOrdinal, int $inlineOrdinal): SourceRange
    {
        $inlineTape = $this->inlineTape($blockOrdinal);

        return new SourceRange(
            $inlineTape->startOffset($inlineOrdinal),
            $inlineTape->endOffset($inlineOrdinal),
        );
    }

    public function inlineMutationPatchRange(NodeId $blockId, int $inlineOrdinal, int $expectedKind): ?SourceRange
    {
        $this->assertInlineKind($blockId, $inlineOrdinal, $expectedKind);

        return isset($this->inlineMarkdownOverrides[$blockId->ordinal])
            ? null
            : $this->inlineRange($blockId->ordinal, $inlineOrdinal);
    }

    public function inlineLinkLabelMarkdown(NodeId $blockId, int $inlineOrdinal, int $expectedKind): string
    {
        $inlineTape = $this->assertInlineKind($blockId, $inlineOrdinal, $expectedKind);
        $start = $inlineTape->startOffset($inlineOrdinal);
        $end = $inlineTape->endOffset($inlineOrdinal);
        $labelEnd = $inlineTape->flags($inlineOrdinal);
        $buffer = $this->inlineSourceBuffer($blockId->ordinal);

        if ($labelEnd < $start || $labelEnd >= $end || ']' !== ($buffer->bytes[$labelEnd] ?? '')) {
            throw new MarkdownInternalException(\sprintf('Inline link node %d has no source label boundary.', $inlineOrdinal));
        }

        return $buffer->substring($start, $labelEnd + 1);
    }

    public function setInlineLink(
        NodeId $blockId,
        int $inlineOrdinal,
        int $expectedKind,
        string $destination,
        ?string $title,
        ?string $imageAltText,
    ): NodeId {
        $inlineTape = $this->assertInlineKind($blockId, $inlineOrdinal, $expectedKind);
        $existingParts = explode("\x00", $inlineTape->payload($inlineOrdinal) ?? '', 3);
        $payload = $destination . "\x00" . ($title ?? '');

        if (InlineKind::IMAGE === $expectedKind) {
            if (null !== $imageAltText) {
                $payload .= "\x00" . $imageAltText;
            } elseif (\array_key_exists(2, $existingParts)) {
                $payload .= "\x00" . $existingParts[2];
            }
        }

        $inlineTape->setPayload($inlineOrdinal, $payload);
        $this->modifiedMarkdownOrdinals[$blockId->ordinal] = true;
        ++$this->generation;
        $this->stampGeneration($blockId->ordinal);
        $this->inlineCache?->put(
            $blockId->ordinal,
            $this->tape->generation($blockId->ordinal),
            $inlineTape,
        );

        return $this->currentNodeId($blockId->ordinal);
    }

    public function inlinePlainText(int $blockOrdinal, int $inlineOrdinal): string
    {
        if (InlineKind::IMAGE === $this->inlineKindId($blockOrdinal, $inlineOrdinal)) {
            $parts = $this->inlinePayloadValues($blockOrdinal, $inlineOrdinal);

            if (\array_key_exists(2, $parts)) {
                return $parts[2];
            }
        }

        return PlainText::fromInlineTape($this->inlineSourceBuffer($blockOrdinal), $this->inlineTape($blockOrdinal), $inlineOrdinal);
    }

    public function inlineTextValue(int $blockOrdinal, int $inlineOrdinal): string
    {
        $inlineTape = $this->inlineTape($blockOrdinal);
        $kind = $inlineTape->kindId($inlineOrdinal);

        if (InlineKind::TEXT === $kind || InlineKind::CODE_SPAN === $kind) {
            return $inlineTape->payload($inlineOrdinal)
                ?? $this->inlineSourceBuffer($blockOrdinal)->substring(
                    $inlineTape->startOffset($inlineOrdinal),
                    $inlineTape->endOffset($inlineOrdinal),
                );
        }

        if (InlineKind::AUTOLINK === $kind) {
            return $this->inlineSourceBuffer($blockOrdinal)->substring(
                $inlineTape->startOffset($inlineOrdinal),
                $inlineTape->endOffset($inlineOrdinal),
            );
        }

        return '';
    }

    public function inlineImageAltText(int $blockOrdinal, int $inlineOrdinal): string
    {
        return $this->inlinePlainText($blockOrdinal, $inlineOrdinal);
    }

    public function inlineImageAltTextOverride(int $blockOrdinal, int $inlineOrdinal): ?string
    {
        $parts = $this->inlinePayloadValues($blockOrdinal, $inlineOrdinal);

        return \array_key_exists(2, $parts) ? $parts[2] : null;
    }

    /**
     * @return array{string, string}
     */
    public function inlinePayloadParts(int $blockOrdinal, int $inlineOrdinal): array
    {
        $parts = $this->inlinePayloadValues($blockOrdinal, $inlineOrdinal);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    public function inlineKindId(int $blockOrdinal, int $inlineOrdinal): int
    {
        return $this->inlineTape($blockOrdinal)->kindId($inlineOrdinal);
    }

    public function inlineFirstChildOrdinal(int $blockOrdinal, int $inlineOrdinal): int
    {
        return $this->inlineTape($blockOrdinal)->firstChildOrdinal($inlineOrdinal);
    }

    public function inlineNextSiblingOrdinal(int $blockOrdinal, int $inlineOrdinal): int
    {
        return $this->inlineTape($blockOrdinal)->nextSiblingOrdinal($inlineOrdinal);
    }

    public function inlineLiteral(int $blockOrdinal, int $inlineOrdinal): string
    {
        $inlineTape = $this->inlineTape($blockOrdinal);
        $buffer = $this->inlineSourceBuffer($blockOrdinal);

        return $inlineTape->payload($inlineOrdinal)
            ?? substr(
                $buffer->bytes,
                $inlineTape->startOffset($inlineOrdinal),
                $inlineTape->endOffset($inlineOrdinal) - $inlineTape->startOffset($inlineOrdinal),
            );
    }

    public function inlineExtensionNode(int $blockOrdinal, int $inlineOrdinal): InlineNode
    {
        return $this->inlineTape($blockOrdinal)->extensionInlineNode($inlineOrdinal);
    }

    public function codeBlockLanguage(int $ordinal): ?string
    {
        if (isset($this->codeBlockOverrides[$ordinal])) {
            return $this->codeBlockOverrides[$ordinal]['language'];
        }

        return $this->blockContent->codeBlockLanguage($ordinal);
    }

    public function codeBlockCode(int $ordinal): string
    {
        if (isset($this->codeBlockOverrides[$ordinal])) {
            return $this->codeBlockOverrides[$ordinal]['code'];
        }

        return $this->blockContent->codeBlockCode($ordinal);
    }

    public function codeBlockParts(int $ordinal): array
    {
        [$language, $info, $code] = $this->blockContent->codeBlockParts($ordinal);
        if (isset($this->codeBlockOverrides[$ordinal])) {
            $language = $this->codeBlockOverrides[$ordinal]['language'];
            $code = $this->codeBlockOverrides[$ordinal]['code'];
        }

        return [$language, $info, $code];
    }

    public function codeBlockFenceIsClosed(int $ordinal): bool
    {
        return $this->blockContent->codeBlockFenceIsClosed($ordinal);
    }

    public function htmlBlockSource(int $ordinal): string
    {
        return $this->blockContent->htmlBlockSource($ordinal);
    }

    public function codeBlockPatchRange(NodeId $id): SourceRange
    {
        $this->assertLiveKind($id, [BlockKind::FENCED_CODE, BlockKind::INDENTED_CODE]);
        $range = $this->range($id);

        if (BlockKind::FENCED_CODE !== $this->tape->kindId($id->ordinal)) {
            return $range;
        }

        return new SourceRange($range->startOffset, $this->lineEndAfter($range->endOffset));
    }

    public function codeBlockLanguageRange(NodeId $id): SourceRange
    {
        $this->assertLiveKind($id, [BlockKind::FENCED_CODE]);
        $markerStart = $this->range($id)->startOffset;
        $lineEnd = \min(
            $markerStart + strcspn($this->buffer->bytes, "\r\n", $markerStart),
            $this->buffer->length,
        );
        $marker = $this->buffer->bytes[$markerStart] ?? '';

        if ('`' !== $marker && '~' !== $marker) {
            throw new MarkdownInternalException(\sprintf('Fenced code block at byte %d has no opening marker.', $markerStart));
        }

        $markerEnd = $markerStart;

        while ($markerEnd < $lineEnd && $marker === $this->buffer->bytes[$markerEnd]) {
            ++$markerEnd;
        }

        $languageStart = $markerEnd;

        while ($languageStart < $lineEnd && (' ' === $this->buffer->bytes[$languageStart] || "\t" === $this->buffer->bytes[$languageStart])) {
            ++$languageStart;
        }

        if ($languageStart === $lineEnd) {
            return new SourceRange($markerEnd, $markerEnd);
        }

        $languageEnd = $languageStart;

        while ($languageEnd < $lineEnd && ' ' !== $this->buffer->bytes[$languageEnd] && "\t" !== $this->buffer->bytes[$languageEnd]) {
            ++$languageEnd;
        }

        return new SourceRange($languageStart, $languageEnd);
    }

    public function fallbackAncestorForRange(SourceRange $range): NodeId
    {
        $best = $this->rootOrdinal;

        if ($range->startOffset === $range->endOffset) {
            return $this->currentNodeId($best);
        }

        foreach ($this->descendants($this->rootOrdinal) as $ordinal) {
            $candidate = $this->fallbackRangeForOrdinal($ordinal);

            if ($candidate->startOffset !== $candidate->endOffset && $this->rangeContains($candidate, $range)) {
                $best = $ordinal;
            }
        }

        return $this->currentNodeId($best);
    }

    public function fallbackPatchRange(NodeId $id): SourceRange
    {
        $this->assertLive($id);

        return $this->fallbackRangeForOrdinal($id->ordinal);
    }

    public function isRoot(NodeId $id): bool
    {
        return $id->ordinal === $this->rootOrdinal;
    }

    public function coreNodeKind(string $name): NodeKind
    {
        return $this->profile->nodeKinds->core($name);
    }

    public function findNodeKind(string $name): ?NodeKind
    {
        return $this->profile->nodeKinds->find($name);
    }

    /**
     * @param list<NodeKind> $kinds
     *
     * @return iterable<NodeHandle>
     */
    public function queryHandles(array $kinds): iterable
    {
        $kindIds = [];

        foreach ($kinds as $kind) {
            $kindIds[] = $kind->id;
        }

        return $this->queryHandlesByKindIds($kindIds, [] !== $kindIds);
    }

    /**
     * @param list<int> $kindIds
     *
     * @return iterable<NodeHandle>
     */
    public function queryHandlesByKindIds(array $kindIds, bool $filterByKind): iterable
    {
        if ($filterByKind && [] === $kindIds) {
            return;
        }

        $wanted = array_fill_keys($kindIds, true);

        foreach ($this->descendants($this->rootOrdinal) as $ordinal) {
            $id = $this->currentNodeId($ordinal);

            if ($filterByKind && !isset($wanted[$this->tape->kindId($ordinal)])) {
                continue;
            }

            yield $this->node($id);
        }
    }

    public function hasAnchor(string $anchor): bool
    {
        return isset($this->slugIndex()[$anchor]);
    }

    public function firstChildOrdinal(int $ordinal): int
    {
        return $this->tape->firstChildOrdinal($ordinal);
    }

    public function nextSiblingOrdinal(int $ordinal): int
    {
        return $this->tape->nextSiblingOrdinal($ordinal);
    }

    public function currentNodeId(int $ordinal): NodeId
    {
        return new NodeId($this->generationFloor + $this->tape->generation($ordinal), $ordinal);
    }

    public function rootNodeId(): NodeId
    {
        return $this->currentNodeId($this->rootOrdinal);
    }

    public function compiledProfile(): CompiledProfile
    {
        return $this->profile;
    }

    public function htmlSourceBytes(): string
    {
        return $this->buffer->bytes;
    }

    public function htmlTape(): ReadOnlyParseTape
    {
        return $this->tape;
    }

    public function htmlRootOrdinal(): int
    {
        return $this->rootOrdinal;
    }

    public function referenceMap(): ReferenceMap
    {
        return $this->referenceMap;
    }

    public function inlineCountBudget(): ?InlineCountBudget
    {
        return $this->inlineCountBudget;
    }

    public function inlineSourceView(int $ordinal): InlineSourceView
    {
        return new InlineSourceView(
            $this->inlineSourceBuffer($ordinal),
            $this->contentPairs($ordinal),
        );
    }

    public function inlineTapeView(int $blockOrdinal, InlineSourceView $source): InlineTapeView
    {
        return new InlineTapeView(
            $source->buffer,
            $this->inlineTape($blockOrdinal, $source),
        );
    }

    public function headingSlug(int $ordinal): string
    {
        if ($this->headingSlugGeneration !== $this->generation) {
            $this->headingSlugs = $this->buildHeadingSlugs();
            $this->headingSlugGeneration = $this->generation;
        }

        return $this->headingSlugs[$ordinal]
            ?? throw new MarkdownInternalException(\sprintf('Block ordinal %d is not a heading.', $ordinal));
    }

    public function inlineMarkdownTapeView(string $markdown): InlineTapeView
    {
        $source = $this->inlineMarkdownSourceView($markdown);

        return new InlineTapeView(
            $source->buffer,
            $this->inlineParser->parse(
                $source->buffer,
                $source->pairs,
                $this->referenceMap,
                $this->inlineCountBudget,
                false,
            ),
        );
    }

    public function inlineMarkdownSourceView(string $markdown): InlineSourceView
    {
        return InlineMarkdownInput::sourceView($markdown);
    }

    public function inlineMarkdownRangesAreOriginal(): bool
    {
        return false;
    }

    public function renderNodeKindName(int $ordinal): string
    {
        return $this->nodeKind($this->currentNodeId($ordinal))->name;
    }

    public function renderNodeKindId(int $ordinal): int
    {
        return $this->tape->kindId($ordinal);
    }

    public function inlineMarkdownSource(int $ordinal): string
    {
        if (isset($this->inlineMarkdownOverrides[$ordinal])) {
            return $this->inlineMarkdownOverrides[$ordinal];
        }

        return $this->blockContent->inlineMarkdownSource($ordinal);
    }

    public function listIsOrdered(int $ordinal): bool
    {
        return 0 !== ($this->tape->flags($ordinal) & 1);
    }

    public function listIsLoose(int $ordinal): bool
    {
        return 0 !== ($this->tape->flags($ordinal) & 2);
    }

    public function listStartNumber(int $ordinal): int
    {
        return (int) ($this->tape->payload($ordinal) ?? '1');
    }

    public function blockPayload(int $ordinal): ?string
    {
        return $this->tape->payload($ordinal);
    }

    public function extensionBlockState(int $ordinal): BlockState
    {
        return $this->tape->extensionBlockState($ordinal);
    }

    /**
     * The front matter block's ordinal, or ParseTape::NONE when the document
     * has none. Front matter is only ever the root's first child, so the
     * lookup is one link read and one kind compare.
     */
    public function frontMatterOrdinal(): int
    {
        $first = $this->tape->firstChildOrdinal($this->rootOrdinal);

        if (ParseTape::NONE === $first) {
            return ParseTape::NONE;
        }

        // Compared by registry name, not by id: the id is positional per
        // profile, and a third-party profile may reserve the same number.
        return FrontMatterExtension::BLOCK_KIND === $this->profile->nodeKinds->get($this->tape->kindId($first))->name
            ? $first
            : ParseTape::NONE;
    }

    /**
     * The undecoded bytes between a front matter block's fences.
     */
    public function frontMatterContent(int $ordinal): string
    {
        if (\array_key_exists($ordinal, $this->frontMatterContentOverrides)) {
            return $this->frontMatterContentOverrides[$ordinal];
        }

        [$start, $end] = $this->frontMatterContentBounds($ordinal);

        return substr($this->source()->bytes, $start, $end - $start);
    }

    public function frontMatterText(int $ordinal): string
    {
        $range = $this->range($this->currentNodeId($ordinal));

        if (!\array_key_exists($ordinal, $this->frontMatterContentOverrides)) {
            return substr($this->source()->bytes, $range->startOffset, $range->endOffset - $range->startOffset);
        }

        [$contentStart, $contentEnd] = $this->frontMatterContentBounds($ordinal);

        return substr($this->source()->bytes, $range->startOffset, $contentStart - $range->startOffset)
            . $this->frontMatterContentOverrides[$ordinal]
            . substr($this->source()->bytes, $contentEnd, $range->endOffset - $contentEnd);
    }

    public function frontMatterContentRange(NodeId $id): SourceRange
    {
        $this->assertFrontMatter($id);
        [$start, $end] = $this->frontMatterContentBounds($id->ordinal);

        return new SourceRange($start, $end);
    }

    public function frontMatterLineEnding(int $ordinal): LineEnding
    {
        $start = $this->tape->startOffset($ordinal);
        $lineEnd = $start + strcspn($this->buffer->bytes, "\r\n", $start);

        if ("\r" === ($this->buffer->bytes[$lineEnd] ?? '')) {
            return "\n" === ($this->buffer->bytes[$lineEnd + 1] ?? '')
                ? LineEnding::CrLf
                : LineEnding::Cr;
        }

        return LineEnding::Lf;
    }

    public function setFrontMatterContent(NodeId $id, string $content): NodeId
    {
        $this->assertFrontMatter($id);
        $this->frontMatterContentOverrides[$id->ordinal] = $content;
        $this->modifiedMarkdownOrdinals[$id->ordinal] = true;
        ++$this->generation;
        $this->stampGeneration($id->ordinal);

        return $this->currentNodeId($id->ordinal);
    }

    public function taskListState(int $ordinal): ?string
    {
        return match ($this->tape->payload($ordinal)) {
            'task:unchecked' => 'unchecked',
            'task:checked' => 'checked',
            default => null,
        };
    }

    /**
     * @return array{list<string>, list<string>, list<list<string>>}
     */
    public function tableParts(int $ordinal): array
    {
        $generation = $this->tape->generation($ordinal);
        $cached = $this->tablePartsCache[$ordinal] ?? null;

        if (null !== $cached && $cached[0] === $generation) {
            return $cached[1];
        }

        $parts = $this->blockContent->tableParts($ordinal);
        $this->tablePartsCache[$ordinal] = [$generation, $parts];

        return $parts;
    }

    /**
     * @return list<array{list<string>, SourceRange}>
     */
    public function tableBodyRows(int $ordinal): array
    {
        return $this->blockContent->tableBodyRows($ordinal);
    }

    public function tableCellHtmlCache(): TableCellHtmlCache
    {
        return $this->tableCellHtmlCache ??= new TableCellHtmlCache();
    }

    /**
     * @return iterable<array{int, string, SourceRange}>
     */
    public function traversalInlineEvents(int $blockOrdinal): iterable
    {
        if (!$this->hasInlineContent($blockOrdinal)) {
            return;
        }

        $inlineTape = $this->inlineTape($blockOrdinal);

        for ($inlineOrdinal = 1; $inlineOrdinal < $inlineTape->count(); ++$inlineOrdinal) {
            yield [
                $inlineOrdinal,
                $this->inlineKindName($inlineTape->kindId($inlineOrdinal)),
                new SourceRange(
                    $inlineTape->startOffset($inlineOrdinal),
                    $inlineTape->endOffset($inlineOrdinal),
                ),
            ];
        }
    }

    /**
     * @return list<string>
     */
    public function unusedReferenceLabels(): array
    {
        return $this->referenceMap->unusedLabels();
    }

    public function referenceDefinitionLabel(int $ordinal): ?string
    {
        return $this->tape->payload($ordinal);
    }

    /**
     * @return list<int>
     */
    private function headingOrdinals(): array
    {
        $headings = [];

        foreach ($this->descendants($this->rootOrdinal) as $ordinal) {
            $kind = $this->tape->kindId($ordinal);

            if (BlockKind::ATX_HEADING === $kind || BlockKind::SETEXT_HEADING === $kind) {
                $headings[] = $ordinal;
            }
        }

        return $headings;
    }

    /**
     * @return \Generator<array{NodeId, int}>
     */
    private function inlineHandles(int $inlineKind): \Generator
    {
        foreach ($this->descendants($this->rootOrdinal) as $blockOrdinal) {
            if (!$this->hasInlineContent($blockOrdinal)) {
                continue;
            }

            $inlineTape = $this->inlineTape($blockOrdinal);

            for ($inlineOrdinal = 0; $inlineOrdinal < $inlineTape->count(); ++$inlineOrdinal) {
                if ($inlineKind === $inlineTape->kindId($inlineOrdinal)) {
                    yield [$this->currentNodeId($blockOrdinal), $inlineOrdinal];
                }
            }
        }
    }

    private function hasInlineContent(int $ordinal): bool
    {
        $kind = $this->tape->kindId($ordinal);

        return match ($kind) {
            BlockKind::PARAGRAPH,
            BlockKind::ATX_HEADING,
            BlockKind::SETEXT_HEADING => true,
            default => DescriptionListExtension::TERM_KIND === $this->profile->nodeKinds->get($kind)->name,
        };
    }

    private function inlineKindName(int $kindId): string
    {
        return match ($kindId) {
            InlineKind::TEXT => 'text',
            InlineKind::SOFT_BREAK => 'soft-break',
            InlineKind::HARD_BREAK => 'hard-break',
            InlineKind::CODE_SPAN => 'code-span',
            InlineKind::EMPHASIS => 'emphasis',
            InlineKind::STRONG => 'strong',
            InlineKind::LINK => 'link',
            InlineKind::IMAGE => 'image',
            InlineKind::AUTOLINK => 'autolink',
            InlineKind::HTML_INLINE => 'html-inline',
            InlineKind::STRIKETHROUGH => 'strikethrough',
            default => $this->profile->nodeKinds->get($kindId)->name,
        };
    }

    /**
     * @param list<int> $headings
     */
    private function sectionEndOffset(array $headings, int $index): int
    {
        $level = $this->tape->flags($headings[$index]);
        $count = \count($headings);

        for ($next = $index + 1; $next < $count; ++$next) {
            if ($this->tape->flags($headings[$next]) <= $level) {
                return $this->tape->startOffset($headings[$next]);
            }
        }

        return $this->tape->endOffset($this->rootOrdinal);
    }

    private function normalizeTitle(string $title): string
    {
        return CaseFold::fold(trim($title));
    }

    /**
     * @return array<string, int>
     */
    private function slugIndex(): array
    {
        $index = [];

        if ($this->headingSlugGeneration !== $this->generation) {
            $this->headingSlugs = $this->buildHeadingSlugs();
            $this->headingSlugGeneration = $this->generation;
        }

        foreach ($this->headingSlugs as $ordinal => $slug) {
            $index[$slug] = $ordinal;
        }

        return $index;
    }

    /**
     * @return array<int, string>
     */
    private function buildHeadingSlugs(): array
    {
        $slugs = [];
        $sequence = new GitHubSlugSequence();

        foreach ($this->headingOrdinals() as $ordinal) {
            $slugs[$ordinal] = $sequence->next($this->plainText($ordinal));
        }

        return $slugs;
    }

    /**
     * @param list<int> $allowedKinds
     */
    private function assertLiveKind(NodeId $id, array $allowedKinds): void
    {
        $this->assertLive($id);

        if (!\in_array($this->tape->kindId($id->ordinal), $allowedKinds, true)) {
            throw new InvalidMarkdownArgumentException(\sprintf('Node ordinal %d has an unsupported kind for this mutation.', $id->ordinal));
        }
    }

    private function assertLive(NodeId $id): void
    {
        if (!$this->exists($id)) {
            throw new StaleHandleException(\sprintf('Node ordinal %d is stale or removed.', $id->ordinal));
        }
    }

    private function assertTopLevelBlock(NodeId $id): void
    {
        $this->assertLive($id);

        if ($this->rootOrdinal !== $this->tape->parentOrdinal($id->ordinal)) {
            throw new InvalidMarkdownArgumentException('General block manipulation is limited to top-level blocks.');
        }
    }

    private function assertInlineKind(NodeId $blockId, int $inlineOrdinal, int $expectedKind): ParseTape
    {
        $this->assertLive($blockId);
        $inlineTape = $this->inlineTape($blockId->ordinal);

        if ($inlineTape->kindId($inlineOrdinal) !== $expectedKind
            || !\in_array($expectedKind, [InlineKind::LINK, InlineKind::IMAGE], true)) {
            throw new InvalidMarkdownArgumentException(\sprintf('Inline node %d has an unsupported kind for this mutation.', $inlineOrdinal));
        }

        return $inlineTape;
    }

    private function assertFrontMatter(NodeId $id): void
    {
        $this->assertLive($id);

        if (FrontMatterExtension::BLOCK_KIND !== $this->nodeKind($id)->name) {
            throw new InvalidMarkdownArgumentException(\sprintf('Node ordinal %d is not front matter.', $id->ordinal));
        }
    }

    /**
     * @return array{int, int}
     */
    private function frontMatterContentBounds(int $ordinal): array
    {
        $payload = $this->tape->payload($ordinal);

        if (null === $payload) {
            $start = $this->tape->endOffset($ordinal);

            return [$start, $start];
        }

        $bounds = explode(':', $payload, 2);
        $start = (int) $bounds[0];

        return [$start, (int) ($bounds[1] ?? $bounds[0])];
    }

    /**
     * Records the current document revision on a slot, which retires every
     * handle taken at an earlier revision. Callers increment $generation first,
     * once per mutation, so one edit that touches a whole subtree stamps every
     * affected slot with the same revision.
     */
    private function stampGeneration(int $ordinal): void
    {
        $this->tape->setGeneration($ordinal, $this->generation - $this->generationFloor);
    }

    /**
     * @return list<NodeId>
     */
    private function insertMarkdownNear(NodeId $target, string $markdown, bool $before): array
    {
        $detached = $this->parseDetached($markdown);
        $sourceRoot = $detached->rootOrdinal;
        $sourceChild = $detached->firstChildOrdinal($sourceRoot);

        if (ParseTape::NONE === $sourceChild) {
            return [];
        }

        ++$this->generation;
        $parent = $this->tape->parentOrdinal($target->ordinal);
        $insertionOffset = $before ? $this->tape->startOffset($target->ordinal) : $this->tape->endOffset($target->ordinal);
        $inserted = $this->importRootChildren($detached, $sourceChild, $parent, $insertionOffset);
        $firstInserted = $inserted[0]->ordinal;
        $lastInserted = $inserted[\count($inserted) - 1]->ordinal;

        if ($before) {
            $previous = $this->previousSibling($target->ordinal);

            if (ParseTape::NONE === $previous) {
                $this->tape->linkFirstChild($parent, $firstInserted);
            } else {
                $this->tape->linkNextSibling($previous, $firstInserted);
            }

            $this->tape->linkNextSibling($lastInserted, $target->ordinal);

            return $inserted;
        }

        $oldNext = $this->tape->nextSiblingOrdinal($target->ordinal);
        $this->tape->linkNextSibling($target->ordinal, $firstInserted);
        $this->tape->linkNextSibling($lastInserted, $oldNext);

        return $inserted;
    }

    /**
     * @return list<NodeId>
     */
    private function importRootChildren(ParsedDocumentModel $detached, int $sourceChild, int $parent, int $insertionOffset): array
    {
        $inserted = [];
        $previous = ParseTape::NONE;
        $child = $sourceChild;

        while (ParseTape::NONE !== $child) {
            $copied = $this->tape->copySubtreeFrom($detached->tape, $child, $parent, $insertionOffset, $insertionOffset, $this->generation - $this->generationFloor);
            $this->copyDetachedOverrides($detached, $child, $copied);
            $this->generatedBlockMarkdown[$copied] = $detached->blockMarkdown($detached->currentNodeId($child));

            if (ParseTape::NONE !== $previous) {
                $this->tape->linkNextSibling($previous, $copied);
            }

            $inserted[] = $this->currentNodeId($copied);
            $previous = $copied;
            $child = $detached->nextSiblingOrdinal($child);
        }

        return $inserted;
    }

    private function copyDetachedOverrides(ParsedDocumentModel $detached, int $sourceOrdinal, int $targetOrdinal): void
    {
        if ($detached->hasInlineContent($sourceOrdinal)) {
            $this->inlineMarkdownOverrides[$targetOrdinal] = $detached->inlineMarkdownSource($sourceOrdinal);
        }

        if (BlockKind::FENCED_CODE === $detached->tape->kindId($sourceOrdinal) || BlockKind::INDENTED_CODE === $detached->tape->kindId($sourceOrdinal)) {
            $this->codeBlockOverrides[$targetOrdinal] = [
                'language' => $detached->codeBlockLanguage($sourceOrdinal),
                'code' => $detached->codeBlockCode($sourceOrdinal),
            ];
        }

        $sourceChild = $detached->firstChildOrdinal($sourceOrdinal);
        $targetChild = $this->firstChildOrdinal($targetOrdinal);

        while (ParseTape::NONE !== $sourceChild && ParseTape::NONE !== $targetChild) {
            $this->copyDetachedOverrides($detached, $sourceChild, $targetChild);
            $sourceChild = $detached->nextSiblingOrdinal($sourceChild);
            $targetChild = $this->nextSiblingOrdinal($targetChild);
        }
    }

    private function subtreeHasMarkdownOverrides(int $ordinal): bool
    {
        if (
            \array_key_exists($ordinal, $this->inlineMarkdownOverrides)
            || \array_key_exists($ordinal, $this->codeBlockOverrides)
            || \array_key_exists($ordinal, $this->frontMatterContentOverrides)
            || isset($this->modifiedMarkdownOrdinals[$ordinal])
        ) {
            return true;
        }

        $child = $this->tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            if ($this->subtreeHasMarkdownOverrides($child)) {
                return true;
            }

            $child = $this->tape->nextSiblingOrdinal($child);
        }

        return false;
    }

    private function subtreeHasMarkdownModifications(int $ordinal): bool
    {
        if (isset($this->modifiedMarkdownOrdinals[$ordinal])) {
            return true;
        }

        $child = $this->tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            if ($this->subtreeHasMarkdownModifications($child)) {
                return true;
            }

            $child = $this->tape->nextSiblingOrdinal($child);
        }

        return false;
    }

    private function parseDetached(string $markdown): ParsedDocumentModel
    {
        return new self(
            $this->syntaxParser->parseFragment($markdown, $this->parseOptions),
            $this->inlineParser,
            $this->syntaxParser,
        );
    }

    private function physicalLineStart(int $offset): int
    {
        $start = $this->logicalSourceStart();

        while ($offset > $start && "\n" !== $this->buffer->bytes[$offset - 1] && "\r" !== $this->buffer->bytes[$offset - 1]) {
            --$offset;
        }

        return $offset;
    }

    private function physicalBlockEnd(int $ordinal): int
    {
        $end = $this->fallbackRangeForOrdinal($ordinal)->endOffset;

        if (
            $end > 0
            && ("\n" === $this->buffer->bytes[$end - 1] || "\r" === $this->buffer->bytes[$end - 1])
        ) {
            return $end;
        }

        return $this->lineEndAfter($end);
    }

    private function logicalSourceStart(): int
    {
        return $this->source()->hasBom ? 3 : 0;
    }

    private function adoptSyntax(ParsedSyntax $syntax): void
    {
        $this->syntax = $syntax;
        $this->buffer = $syntax->buffer();
        $this->tape = $syntax->newWorkspaceTape();
        $this->rootOrdinal = $syntax->rootOrdinal();
        $this->profile = $syntax->profile();
        $this->referenceMap = $syntax->newReferenceMap();
        $this->parseOptions = $syntax->parseOptions();
        $maxInlineCount = $this->parseOptions->maxInlineCount;
        $this->inlineCountBudget = 0 === $maxInlineCount ? null : new InlineCountBudget($maxInlineCount);
        $this->blockContent = new BlockContentReader($this->buffer, $this->tape);
    }

    private function previousSibling(int $ordinal): int
    {
        $parent = $this->tape->parentOrdinal($ordinal);
        $child = $this->tape->firstChildOrdinal($parent);
        $previous = ParseTape::NONE;

        while (ParseTape::NONE !== $child) {
            if ($child === $ordinal) {
                return $previous;
            }

            $previous = $child;
            $child = $this->tape->nextSiblingOrdinal($child);
        }

        throw new MarkdownInternalException(\sprintf('Node ordinal %d is not linked from its parent.', $ordinal));
    }

    private function fallbackRangeForOrdinal(int $ordinal): SourceRange
    {
        if ($ordinal === $this->rootOrdinal) {
            return new SourceRange(0, $this->buffer->length);
        }

        $range = new SourceRange(
            $this->tape->startOffset($ordinal),
            $this->tape->endOffset($ordinal),
        );

        if (BlockKind::FENCED_CODE !== $this->tape->kindId($ordinal)) {
            return $range;
        }

        return new SourceRange($range->startOffset, $this->lineEndAfter($range->endOffset));
    }

    private function rangeContains(SourceRange $outer, SourceRange $inner): bool
    {
        return $outer->startOffset <= $inner->startOffset && $outer->endOffset >= $inner->endOffset;
    }

    private function lineEndAfter(int $offset): int
    {
        $length = $this->buffer->length;

        if ($offset >= $length) {
            return $length;
        }

        $end = $offset + strcspn($this->buffer->bytes, "\r\n", $offset);

        if ($end >= $length) {
            return $length;
        }

        if ("\r" === $this->buffer->bytes[$end] && $end + 1 < $length && "\n" === $this->buffer->bytes[$end + 1]) {
            return $end + 2;
        }

        return $end + 1;
    }

    private function previousLineEndingStart(int $offset): ?int
    {
        if ($offset <= 0) {
            return null;
        }

        if ("\n" === $this->buffer->bytes[$offset - 1]) {
            return $offset > 1 && "\r" === $this->buffer->bytes[$offset - 2]
                ? $offset - 2
                : $offset - 1;
        }

        return "\r" === $this->buffer->bytes[$offset - 1] ? $offset - 1 : null;
    }

    private function lastChildOrdinal(int $parent): int
    {
        $last = ParseTape::NONE;
        $child = $this->tape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $child) {
            if (!isset($this->removedOrdinals[$child])) {
                $last = $child;
            }

            $child = $this->tape->nextSiblingOrdinal($child);
        }

        return $last;
    }

    private function removeSectionBody(NodeId $headingId, int $endOffset): void
    {
        foreach ($this->sectionBodyOrdinals($headingId->ordinal, $endOffset) as $ordinal) {
            if ($this->exists($this->currentNodeId($ordinal))) {
                $this->removeBlock($this->currentNodeId($ordinal));
            }
        }
    }

    private function lastSectionBodyOrdinal(int $headingOrdinal, int $endOffset): int
    {
        $last = ParseTape::NONE;

        foreach ($this->sectionBodyOrdinals($headingOrdinal, $endOffset) as $ordinal) {
            $last = $ordinal;
        }

        return $last;
    }

    /**
     * @return list<int>
     */
    private function sectionOrdinals(int $headingOrdinal, int $endOffset): array
    {
        return [$headingOrdinal, ...$this->sectionBodyOrdinals($headingOrdinal, $endOffset)];
    }

    /**
     * @return list<int>
     */
    private function sectionBodyOrdinals(int $headingOrdinal, int $endOffset): array
    {
        $ordinals = [];
        $ordinal = $this->tape->nextSiblingOrdinal($headingOrdinal);

        while (ParseTape::NONE !== $ordinal) {
            if ($this->tape->startOffset($ordinal) >= $endOffset) {
                break;
            }

            if (!isset($this->removedOrdinals[$ordinal])) {
                $ordinals[] = $ordinal;
            }

            $ordinal = $this->tape->nextSiblingOrdinal($ordinal);
        }

        return $ordinals;
    }

    private function unlinkOrdinal(int $ordinal): void
    {
        $parent = $this->tape->parentOrdinal($ordinal);
        $next = $this->tape->nextSiblingOrdinal($ordinal);
        $previous = $this->previousSibling($ordinal);

        if (ParseTape::NONE === $previous) {
            $this->tape->linkFirstChild($parent, $next);
        } else {
            $this->tape->linkNextSibling($previous, $next);
        }

        $this->tape->linkNextSibling($ordinal, ParseTape::NONE);
    }

    private function moveBlock(NodeId $target, NodeId $anchor, bool $before): void
    {
        $this->assertLive($target);
        $this->assertLive($anchor);

        if ($target->ordinal === $anchor->ordinal) {
            return;
        }

        if ($this->tape->parentOrdinal($target->ordinal) !== $this->tape->parentOrdinal($anchor->ordinal)) {
            throw new InvalidMarkdownArgumentException('Block moves are limited to siblings with the same parent.');
        }

        if (
            ($before && $this->tape->nextSiblingOrdinal($target->ordinal) === $anchor->ordinal)
            || (!$before && $this->tape->nextSiblingOrdinal($anchor->ordinal) === $target->ordinal)
        ) {
            return;
        }

        ++$this->generation;
        $this->unlinkOrdinal($target->ordinal);

        if ($before) {
            $previous = $this->previousSibling($anchor->ordinal);

            if (ParseTape::NONE === $previous) {
                $this->tape->linkFirstChild($this->tape->parentOrdinal($anchor->ordinal), $target->ordinal);
            } else {
                $this->tape->linkNextSibling($previous, $target->ordinal);
            }

            $this->tape->linkNextSibling($target->ordinal, $anchor->ordinal);
            $this->stampGeneration($target->ordinal);

            return;
        }

        $oldNext = $this->tape->nextSiblingOrdinal($anchor->ordinal);
        $this->tape->linkNextSibling($anchor->ordinal, $target->ordinal);
        $this->tape->linkNextSibling($target->ordinal, $oldNext);
        $this->stampGeneration($target->ordinal);
    }

    private function markSubtreeRemoved(int $ordinal): void
    {
        $this->removedOrdinals[$ordinal] = true;
        $this->stampGeneration($ordinal);

        $child = $this->tape->firstChildOrdinal($ordinal);

        while (ParseTape::NONE !== $child) {
            $this->markSubtreeRemoved($child);
            $child = $this->tape->nextSiblingOrdinal($child);
        }
    }

    /**
     * @return \Generator<int>
     */
    private function descendants(int $parent): \Generator
    {
        $child = $this->tape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $child) {
            if (!isset($this->removedOrdinals[$child])) {
                yield $child;
                yield from $this->descendants($child);
            }

            $child = $this->tape->nextSiblingOrdinal($child);
        }
    }

    private function inlineTape(int $ordinal, ?InlineSourceView $source = null): ParseTape
    {
        $generation = $this->tape->generation($ordinal);
        $cache = $this->inlineCache ??= new InlineCache();
        $cached = $cache->get($ordinal, $generation);

        if (null !== $cached) {
            return $cached;
        }

        $source ??= $this->inlineSourceView($ordinal);
        $inlineTape = $this->inlineParser->parse(
            $source->buffer,
            $source->pairs,
            $this->referenceMap,
            $this->inlineCountBudget,
            !isset($this->inlineMarkdownOverrides[$ordinal]),
        );
        $cache->put($ordinal, $generation, $inlineTape);

        return $inlineTape;
    }

    /**
     * @return list<string>
     */
    private function inlinePayloadValues(int $blockOrdinal, int $inlineOrdinal): array
    {
        return explode("\x00", $this->inlineTape($blockOrdinal)->payload($inlineOrdinal) ?? '', 3);
    }

    private function inlineSourceBuffer(int $ordinal): SourceBuffer
    {
        if (isset($this->inlineMarkdownOverrides[$ordinal])) {
            return new SourceBuffer($this->inlineMarkdownOverrides[$ordinal]);
        }

        return $this->buffer;
    }

    /**
     * @return list<array{int, int, int}>
     */
    private function contentPairs(int $ordinal): array
    {
        if (isset($this->inlineMarkdownOverrides[$ordinal])) {
            return [[0, \strlen($this->inlineMarkdownOverrides[$ordinal]), 0]];
        }

        return $this->blockContent->contentPairs($ordinal);
    }
}
