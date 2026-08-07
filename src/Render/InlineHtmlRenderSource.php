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

use Alto\Markdown\Exception\SourceSizeLimitException;
use Alto\Markdown\Parser\Inline\InlineMarkdownInput;
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\Inline\InlineSourceView;
use Alto\Markdown\Parser\Inline\InlineTapeView;
use Alto\Markdown\Parser\InlineCountBudget;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Parser\ReferenceMap;
use Alto\Markdown\Profile\CompiledProfile;

/**
 * Transient source for one standalone inline conversion.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class InlineHtmlRenderSource implements InlineMarkdownRenderSource
{
    private ReferenceMap $references;

    private ?InlineCountBudget $inlineCountBudget;

    public function __construct(
        private CompiledProfile $profile,
        private InlineParser $inlineParser,
        private ParseOptions $options,
    ) {
        $this->references = new ReferenceMap();
        $this->inlineCountBudget = 0 === $options->maxInlineCount
            ? null
            : new InlineCountBudget($options->maxInlineCount);
    }

    public function compiledProfile(): CompiledProfile
    {
        return $this->profile;
    }

    public function referenceMap(): ReferenceMap
    {
        return $this->references;
    }

    public function inlineCountBudget(): ?InlineCountBudget
    {
        return $this->inlineCountBudget;
    }

    public function inlineMarkdownSourceView(string $markdown): InlineSourceView
    {
        $sourceBytes = \strlen($markdown);

        if (0 !== $this->options->maxSourceBytes && $sourceBytes > $this->options->maxSourceBytes) {
            throw new SourceSizeLimitException($this->options->maxSourceBytes, $sourceBytes);
        }

        return InlineMarkdownInput::sourceView($markdown);
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
            ),
        );
    }

    public function inlineMarkdownRangesAreOriginal(): bool
    {
        return true;
    }

    public function tableCellHtmlCache(): ?TableCellHtmlCache
    {
        return null;
    }
}
