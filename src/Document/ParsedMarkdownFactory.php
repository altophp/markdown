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

use Alto\Markdown\Builder\MarkdownBuilder;
use Alto\Markdown\Builder\MarkdownFragmentBuilder;
use Alto\Markdown\Builder\ParsedMarkdownBuilder;
use Alto\Markdown\Builder\ParsedMarkdownFragmentBuilder;
use Alto\Markdown\Exception\FileReadException;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\MarkdownFile;
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Parser\SyntaxParser;
use Alto\Markdown\Profile\CompiledProfile;
use Alto\Markdown\Profile\ComposedProfile;
use Alto\Markdown\Profile\Profile;
use Alto\Markdown\Profile\ProfileCompiler;
use Alto\Markdown\Render\FusedInlineRenderer;
use Alto\Markdown\Render\HtmlInlineRenderer;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\HtmlRendererEngine;
use Alto\Markdown\Render\InlineHtmlRenderSource;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Render\SyntaxHtmlRenderSource;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ParsedMarkdownFactory implements MarkdownFactory
{
    private CompiledProfile $compiledProfile;

    private InlineParser $inlineParser;

    private SyntaxParser $syntaxParser;

    private HtmlRendererEngine $htmlRenderer;

    public function __construct(private Profile $profile)
    {
        $this->compiledProfile = new ProfileCompiler()->compile($profile);
        $this->inlineParser = new InlineParser($this->compiledProfile);
        $this->syntaxParser = new SyntaxParser($this->compiledProfile);
        $this->htmlRenderer = new HtmlRendererEngine(
            [] === $this->compiledProfile->htmlInlineDecorators
                ? new FusedInlineRenderer($this->compiledProfile)
                : new HtmlInlineRenderer(),
        );
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    public function with(ExtensionInterface ...$extensions): self
    {
        if ([] === $extensions) {
            return $this;
        }

        return new self(new ComposedProfile($this->profile, array_values($extensions)));
    }

    public function open(string $path, ?ParseOptions $options = null): MarkdownFile
    {
        $directory = realpath(\dirname($path));

        if (false === $directory) {
            throw new FileReadException(\sprintf('Unable to read Markdown file "%s".', $path), $path);
        }

        $anchoredPath = \rtrim($directory, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . \basename($path);

        if (false !== @lstat($anchoredPath) && !is_file($anchoredPath)) {
            throw new FileReadException(\sprintf('Unable to read regular Markdown file "%s".', $path), $path);
        }

        $source = @file_get_contents($anchoredPath);

        if (false === $source) {
            throw new FileReadException(\sprintf('Unable to read Markdown file "%s".', $path), $path);
        }

        return new ParsedMarkdownFile($this->profile, $this->parse($source, $options), $path, $anchoredPath);
    }

    public function fromString(string $source, ?ParseOptions $options = null): MarkdownDocument
    {
        return new ParsedMarkdownDocument($this->profile, $this->parse($source, $options));
    }

    public function toHtml(string $source, ?ParseOptions $parseOptions = null, ?RenderOptions $renderOptions = null): string
    {
        if (Instrumentation::$timing) {
            Instrumentation::enter('syntax-parse');
        }

        $renderSource = new SyntaxHtmlRenderSource(
            $this->syntaxParser->parse($source, $parseOptions),
            $this->inlineParser,
        );

        if (Instrumentation::$timing) {
            Instrumentation::leave('syntax-parse');
        }

        return $this->htmlRenderer->renderDocument($renderSource, $renderOptions->htmlPolicy ?? HtmlPolicy::safe());
    }

    public function toInlineHtml(string $source, ?ParseOptions $parseOptions = null, ?RenderOptions $renderOptions = null): string
    {
        return $this->htmlRenderer->renderInlineMarkdown(
            new InlineHtmlRenderSource(
                $this->compiledProfile,
                $this->inlineParser,
                $parseOptions ?? new ParseOptions(),
            ),
            $source,
            $renderOptions->htmlPolicy ?? HtmlPolicy::safe(),
        );
    }

    public function builder(): MarkdownBuilder
    {
        return new ParsedMarkdownBuilder($this);
    }

    public function fragment(): MarkdownFragmentBuilder
    {
        return new ParsedMarkdownFragmentBuilder();
    }

    private function parse(string $source, ?ParseOptions $options): ParsedDocumentModel
    {
        return new ParsedDocumentModel(
            $this->syntaxParser->parse($source, $options),
            $this->inlineParser,
            $this->syntaxParser,
        );
    }
}
