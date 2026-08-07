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

use Alto\Markdown\Document\GitHubSlugSequence;
use Alto\Markdown\Document\PlainText;
use Alto\Markdown\Parser\Block\BlockContentReader;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\Inline\InlineMarkdownInput;
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\Inline\InlineSourceView;
use Alto\Markdown\Parser\Inline\InlineTapeView;
use Alto\Markdown\Parser\InlineCountBudget;
use Alto\Markdown\Parser\ParsedSyntax;
use Alto\Markdown\Parser\ReadOnlyParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use Alto\Markdown\Profile\CompiledProfile;

/**
 * Transient HTML view over immutable parsed syntax.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class SyntaxHtmlRenderSource implements HtmlRenderSource
{
    private readonly BlockContentReader $blocks;

    private readonly ReferenceMap $references;

    private readonly ?InlineCountBudget $inlineCountBudget;

    /**
     * @var array<int, InlineTapeView>
     */
    private array $inlineTapes = [];

    /**
     * @var array<int, string>|null
     */
    private ?array $headingSlugs = null;

    public function __construct(
        private readonly ParsedSyntax $syntax,
        private readonly InlineParser $inlineParser,
    ) {
        $this->blocks = new BlockContentReader($syntax->buffer(), $syntax->tape());
        $this->references = $syntax->newReferenceMap();
        $maxInlineCount = $syntax->parseOptions()->maxInlineCount;
        $this->inlineCountBudget = 0 === $maxInlineCount ? null : new InlineCountBudget($maxInlineCount);
    }

    public function htmlSourceBytes(): string
    {
        return $this->syntax->buffer()->bytes;
    }

    public function htmlTape(): ReadOnlyParseTape
    {
        return $this->syntax->tape();
    }

    public function htmlRootOrdinal(): int
    {
        return $this->syntax->rootOrdinal();
    }

    public function compiledProfile(): CompiledProfile
    {
        return $this->syntax->profile();
    }

    public function referenceMap(): ReferenceMap
    {
        return $this->references;
    }

    public function inlineCountBudget(): ?InlineCountBudget
    {
        return $this->inlineCountBudget;
    }

    public function inlineSourceView(int $ordinal): InlineSourceView
    {
        return new InlineSourceView(
            $this->syntax->buffer(),
            $this->blocks->contentPairs($ordinal),
        );
    }

    public function inlineTapeView(int $blockOrdinal, InlineSourceView $source): InlineTapeView
    {
        return $this->inlineTapes[$blockOrdinal] ??= new InlineTapeView(
            $source->buffer,
            $this->inlineParser->parse(
                $source->buffer,
                $source->pairs,
                $this->references,
                $this->inlineCountBudget,
            ),
        );
    }

    public function headingSlug(int $ordinal): string
    {
        $slugs = $this->headingSlugs ??= $this->buildHeadingSlugs();

        return $slugs[$ordinal]
            ?? throw new \LogicException(\sprintf('Block ordinal %d is not a heading.', $ordinal));
    }

    public function inlineMarkdownTapeView(string $markdown): InlineTapeView
    {
        $source = $this->inlineMarkdownSourceView($markdown);

        return new InlineTapeView(
            $source->buffer,
            $this->inlineParser->parse(
                $source->buffer,
                $source->pairs,
                $this->references,
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

    public function codeBlockLanguage(int $ordinal): ?string
    {
        return $this->blocks->codeBlockLanguage($ordinal);
    }

    public function codeBlockCode(int $ordinal): string
    {
        return $this->blocks->codeBlockCode($ordinal);
    }

    public function codeBlockParts(int $ordinal): array
    {
        return $this->blocks->codeBlockParts($ordinal);
    }

    public function htmlBlockSource(int $ordinal): string
    {
        return $this->blocks->htmlBlockSource($ordinal);
    }

    public function tableParts(int $ordinal): array
    {
        return $this->blocks->tableParts($ordinal);
    }

    public function tableCellHtmlCache(): ?TableCellHtmlCache
    {
        // Direct conversion renders each document once: no cell state to reuse.
        return null;
    }

    /**
     * @return array<int, string>
     */
    private function buildHeadingSlugs(): array
    {
        $slugs = [];
        $sequence = new GitHubSlugSequence();
        $tape = $this->syntax->tape();

        for ($ordinal = 1; $ordinal < $tape->count(); ++$ordinal) {
            $kind = $tape->kindId($ordinal);
            if (BlockKind::ATX_HEADING !== $kind && BlockKind::SETEXT_HEADING !== $kind) {
                continue;
            }

            $source = $this->inlineSourceView($ordinal);
            $view = $this->inlineTapeView($ordinal, $source);
            $slugs[$ordinal] = $sequence->next(PlainText::fromInlineTape($view->buffer, $view->tape, 0));
        }

        return $slugs;
    }
}
