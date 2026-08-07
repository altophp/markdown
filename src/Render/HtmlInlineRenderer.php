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
use Alto\Markdown\Extension\Inline\LinkLikeInlineRenderer;
use Alto\Markdown\Extension\LinkRewrite\CompiledLinkDestinationRewriter;
use Alto\Markdown\Parser\Inline\Href;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ParseTapeColumns;

/**
 * Walks a fully parsed inline tape through one copy-on-write column snapshot:
 * the tape is complete before rendering starts and rendering never mutates
 * it, so direct array indexing inside the walk is safe.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HtmlInlineRenderer implements BlockInlineRenderer
{
    private HtmlPolicy $policy;

    private bool $rangesAreOriginal = true;

    private ?DocumentRenderPlan $documentRenderPlan = null;

    public function __construct()
    {
        $this->policy = HtmlPolicy::safe();
    }

    public function useDocumentRenderPlan(?DocumentRenderPlan $plan): void
    {
        $this->documentRenderPlan = $plan;
    }

    public function renderBlockInlines(HtmlRenderSource $source, int $blockOrdinal, bool $hardBreaks, HtmlPolicy $policy): string
    {
        $this->policy = $policy;
        $this->rangesAreOriginal = true;

        if (Instrumentation::$timing) {
            Instrumentation::enter('inline-build');
        }

        $inlineSource = $source->inlineSourceView($blockOrdinal);
        $decorators = $source->compiledProfile()->htmlInlineDecorators;

        if (Instrumentation::$timing) {
            Instrumentation::leave('inline-build');
            Instrumentation::enter('plain-scan');
        }

        $plain = [] === $decorators && null === $source->inlineCountBudget()
            ? PlainInlineText::renderSource($inlineSource, $source->compiledProfile())
            : null;

        if (Instrumentation::$timing) {
            Instrumentation::leave('plain-scan');
        }

        if (null !== $plain) {
            return $plain;
        }

        $view = $source->inlineTapeView($blockOrdinal, $inlineSource);

        return [] === $decorators
            ? $this->renderTapeChildren($source, $view->buffer, $view->tape->columns(), 0, $hardBreaks)
            : $this->renderDecoratedTapeChildren($source, $view->buffer, $view->tape->columns(), 0, $hardBreaks, $decorators);
    }

    public function renderMarkdown(InlineMarkdownRenderSource $source, string $markdown, HtmlPolicy $policy): string
    {
        $this->policy = $policy;
        $this->rangesAreOriginal = $source->inlineMarkdownRangesAreOriginal();

        $cache = $source->tableCellHtmlCache();

        if (null === $cache) {
            return $this->renderMarkdownTape($source, $markdown);
        }

        $key = $policy->cacheKey()."\x00".$markdown;
        $cached = $cache->get($key);

        if (null !== $cached) {
            return $cached;
        }

        $html = $this->renderMarkdownTape($source, $markdown);
        $cache->put($key, $html);

        return $html;
    }

    public function renderHeadingInlines(HtmlRenderSource $source, int $blockOrdinal, bool $hardBreaks, HtmlPolicy $policy): string
    {
        return $this->renderBlockInlines($source, $blockOrdinal, $hardBreaks, $policy);
    }

    private function renderMarkdownTape(InlineMarkdownRenderSource $source, string $markdown): string
    {
        $view = $source->inlineMarkdownTapeView($markdown);
        $decorators = $source->compiledProfile()->htmlInlineDecorators;

        return [] === $decorators
            ? $this->renderTapeChildren($source, $view->buffer, $view->tape->columns(), 0, true)
            : $this->renderDecoratedTapeChildren($source, $view->buffer, $view->tape->columns(), 0, true, $decorators);
    }

    private function renderTapeChildren(
        InlineMarkdownRenderSource $source,
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $parent,
        bool $hardBreaks,
        bool $insideStrong = false,
        bool $insideLink = false,
    ): string {
        $html = '';
        $kinds = $columns->kind;
        $starts = $columns->startOffset;
        $ends = $columns->endOffset;
        $payloads = $columns->payload;
        $siblings = $columns->nextSibling;
        $node = $columns->firstChild[$parent];
        $destinationRewriter = $source->compiledProfile()->linkDestinationRewriter;
        $customLinks = $source->compiledProfile()->inlineLinks;

        while (ParseTape::NONE !== $node) {
            $kind = $kinds[$node];
            $accumulated = $this->accumulateCustomTape($source, $buffer, $columns, $node, $html);
            if (null !== $accumulated) {
                $html = $accumulated;
                $node = $siblings[$node];

                continue;
            }

            $linkAttributes = null;
            if (null !== $destinationRewriter && (
                InlineKind::AUTOLINK === $kind
                || InlineKind::LINK === $kind
                || InlineKind::IMAGE === $kind
                || isset($customLinks[$kind])
            )) {
                $linkAttributes = $this->rewrittenLinkAttributes(
                    $source,
                    $buffer,
                    $columns,
                    $node,
                    $kind,
                    $destinationRewriter,
                );
            }
            $rendered = match ($kind) {
                InlineKind::TEXT => HtmlEscaper::text($payloads[$node] ?? $buffer->substring($starts[$node], $ends[$node])),
                InlineKind::SOFT_BREAK => "\n",
                InlineKind::HARD_BREAK => $hardBreaks ? "<br />\n" : "\n",
                InlineKind::CODE_SPAN => '<code>'.HtmlEscaper::text($payloads[$node] ?? $buffer->substring($starts[$node], $ends[$node])).'</code>',
                InlineKind::AUTOLINK => $this->autolinkTape($buffer, $columns, $node, $linkAttributes),
                InlineKind::HTML_INLINE => $this->rawHtml($source, $payloads[$node] ?? $buffer->substring($starts[$node], $ends[$node])),
                InlineKind::EMPHASIS => '<em>'.$this->renderTapeChildren($source, $buffer, $columns, $node, $hardBreaks, insideLink: $insideLink).'</em>',
                InlineKind::STRONG => $this->strongTape($source, $buffer, $columns, $node, $hardBreaks, $insideStrong, $insideLink),
                InlineKind::STRIKETHROUGH => '<del>'.$this->renderTapeChildren($source, $buffer, $columns, $node, $hardBreaks, insideLink: $insideLink).'</del>',
                InlineKind::LINK => $this->linkTape($source, $buffer, $columns, $node, $hardBreaks, $linkAttributes),
                InlineKind::IMAGE => $this->imageTape($buffer, $columns, $node, $linkAttributes),
                default => $this->customTape($source, $buffer, $columns, $node, $insideLink),
            };
            $rendered = $this->rewriteCustomLinkOutput($source, $kind, $linkAttributes, $rendered);
            $html .= $this->decorateLinkOutput($source, $buffer, $columns, $node, $kind, $rendered, $insideLink, $linkAttributes);
            $node = $siblings[$node];
        }

        return $html;
    }

    /**
     * @param array<int, CompiledHtmlDecoratorChain> $decorators
     */
    private function renderDecoratedTapeChildren(
        InlineMarkdownRenderSource $source,
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $parent,
        bool $hardBreaks,
        array $decorators,
        bool $insideStrong = false,
        bool $insideLink = false,
    ): string {
        $html = '';
        $kinds = $columns->kind;
        $starts = $columns->startOffset;
        $ends = $columns->endOffset;
        $payloads = $columns->payload;
        $siblings = $columns->nextSibling;
        $node = $columns->firstChild[$parent];
        $destinationRewriter = $source->compiledProfile()->linkDestinationRewriter;
        $customLinks = $source->compiledProfile()->inlineLinks;

        while (ParseTape::NONE !== $node) {
            $kind = $kinds[$node];
            $accumulated = $this->accumulateCustomTape($source, $buffer, $columns, $node, $html);
            if (null !== $accumulated) {
                $html = $accumulated;
                $node = $siblings[$node];

                continue;
            }

            $linkAttributes = null;
            if (null !== $destinationRewriter && (
                InlineKind::AUTOLINK === $kind
                || InlineKind::LINK === $kind
                || InlineKind::IMAGE === $kind
                || isset($customLinks[$kind])
            )) {
                $linkAttributes = $this->rewrittenLinkAttributes(
                    $source,
                    $buffer,
                    $columns,
                    $node,
                    $kind,
                    $destinationRewriter,
                );
            }
            $rendered = match ($kind) {
                InlineKind::TEXT => HtmlEscaper::text($payloads[$node] ?? $buffer->substring($starts[$node], $ends[$node])),
                InlineKind::SOFT_BREAK => "\n",
                InlineKind::HARD_BREAK => $hardBreaks ? "<br />\n" : "\n",
                InlineKind::CODE_SPAN => '<code>'.HtmlEscaper::text($payloads[$node] ?? $buffer->substring($starts[$node], $ends[$node])).'</code>',
                InlineKind::AUTOLINK => $this->autolinkTape($buffer, $columns, $node, $linkAttributes),
                InlineKind::HTML_INLINE => $this->rawHtml($source, $payloads[$node] ?? $buffer->substring($starts[$node], $ends[$node])),
                InlineKind::EMPHASIS => '<em>'.$this->renderDecoratedTapeChildren($source, $buffer, $columns, $node, $hardBreaks, $decorators, insideLink: $insideLink).'</em>',
                InlineKind::STRONG => $insideStrong && $source->compiledProfile()->strikethrough
                    ? $this->renderDecoratedTapeChildren($source, $buffer, $columns, $node, $hardBreaks, $decorators, true, $insideLink)
                    : '<strong>'.$this->renderDecoratedTapeChildren($source, $buffer, $columns, $node, $hardBreaks, $decorators, true, $insideLink).'</strong>',
                InlineKind::STRIKETHROUGH => '<del>'.$this->renderDecoratedTapeChildren($source, $buffer, $columns, $node, $hardBreaks, $decorators, insideLink: $insideLink).'</del>',
                InlineKind::LINK => $this->decoratedLinkTape($source, $buffer, $columns, $node, $hardBreaks, $decorators, $linkAttributes),
                InlineKind::IMAGE => $this->imageTape($buffer, $columns, $node, $linkAttributes),
                default => $this->customTape($source, $buffer, $columns, $node, $insideLink),
            };
            $rendered = $this->rewriteCustomLinkOutput($source, $kind, $linkAttributes, $rendered);
            $rendered = $this->decorateLinkOutput($source, $buffer, $columns, $node, $kind, $rendered, $insideLink, $linkAttributes);

            $decorator = $decorators[$kind] ?? null;

            if (null !== $decorator) {
                $rendered = $decorator->decorate(
                    $this->inlineNodeContext($source, $buffer, $columns, $node, $kind, $linkAttributes),
                    $rendered,
                );
            }

            $html .= $rendered;
            $node = $siblings[$node];
        }

        return $html;
    }

    /**
     * @param array<int, CompiledHtmlDecoratorChain>                             $decorators
     * @param array{destination: string, title?: string|null, alt?: string}|null $linkAttributes
     */
    private function decoratedLinkTape(
        InlineMarkdownRenderSource $source,
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $node,
        bool $hardBreaks,
        array $decorators,
        ?array $linkAttributes,
    ): string {
        [$href, $title] = explode("\x00", ($columns->payload[$node] ?? "\x00")."\x00", 3);
        $href = $linkAttributes['destination'] ?? $href;
        $attribute = '' === $title ? '' : ' title="'.HtmlEscaper::attribute($title).'"';

        return '<a href="'.HtmlEscaper::attribute($this->url($href)).'"'.$attribute.'>'
            .$this->renderDecoratedTapeChildren($source, $buffer, $columns, $node, $hardBreaks, $decorators, insideLink: true)
            .'</a>';
    }

    /**
     * @param array{destination: string, title?: string|null, alt?: string}|null $linkAttributes
     */
    private function inlineNodeContext(
        InlineMarkdownRenderSource $source,
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $node,
        int $kind,
        ?array $linkAttributes = null,
    ): HtmlNodeOutputContext {
        $start = $columns->startOffset[$node];
        $end = $columns->endOffset[$node];
        $payload = $columns->payload[$node] ?? null;
        $attributes = $linkAttributes ?? match ($kind) {
            InlineKind::TEXT, InlineKind::CODE_SPAN => [
                'text' => $payload ?? $buffer->substring($start, $end),
            ],
            InlineKind::AUTOLINK => ['destination' => $payload ?? ''],
            InlineKind::LINK => $this->linkAttributes($payload),
            InlineKind::IMAGE => $this->imageAttributes($payload, $buffer, $columns, $node),
            default => null === ($link = $source->compiledProfile()->inlineLinks[$kind] ?? null)
                ? []
                : $link->attributes($columns->extensionInlineNode[$node]),
        };

        return new HtmlNodeOutputContext(
            $this->inlineKindName($source, $kind),
            $this->rangesAreOriginal ? new \Alto\Markdown\Source\SourceRange($start, $end) : null,
            $buffer->substring($start, $end),
            $this->policy,
            $attributes,
        );
    }

    /**
     * @return array{destination: string, title: string}
     */
    private function linkAttributes(?string $payload): array
    {
        [$destination, $title] = explode("\x00", ($payload ?? "\x00")."\x00", 3);

        return ['destination' => $destination, 'title' => $title];
    }

    /**
     * @return array{destination: string, title: string, alt: string}
     */
    private function imageAttributes(?string $payload, SourceBuffer $buffer, ParseTapeColumns $columns, int $node): array
    {
        $parts = explode("\x00", $payload ?? '', 3);

        return [
            'destination' => $parts[0] ?? '',
            'title' => $parts[1] ?? '',
            'alt' => $parts[2] ?? $this->altText($buffer, $columns, $node),
        ];
    }

    /**
     * @return array{destination: string, title?: string|null, alt?: string}|null
     */
    private function rewrittenLinkAttributes(
        InlineMarkdownRenderSource $source,
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $node,
        int $kind,
        CompiledLinkDestinationRewriter $rewriter,
    ): ?array {
        $payload = $columns->payload[$node] ?? null;
        $attributes = match ($kind) {
            InlineKind::AUTOLINK => ['destination' => $payload ?? ''],
            InlineKind::LINK => $this->linkAttributes($payload),
            InlineKind::IMAGE => $this->imageAttributes($payload, $buffer, $columns, $node),
            default => null === ($link = $source->compiledProfile()->inlineLinks[$kind] ?? null)
                ? null
                : $link->attributes($columns->extensionInlineNode[$node]),
        };

        if (null === $attributes) {
            return null;
        }

        $start = $columns->startOffset[$node];
        $end = $columns->endOffset[$node];
        $range = $this->rangesAreOriginal
            ? new \Alto\Markdown\Source\SourceRange($start, $end)
            : null;
        $destination = Href::encode($attributes['destination']);
        $attributes['destination'] = Href::encode($rewriter->rewrite(
            $this->inlineKindName($source, $kind),
            $destination,
            $range,
            $buffer->substring($start, $end),
        ));

        return $attributes;
    }

    /**
     * @param array{destination: string, title?: string|null, alt?: string}|null $linkAttributes
     */
    private function rewriteCustomLinkOutput(
        InlineMarkdownRenderSource $source,
        int $kind,
        ?array $linkAttributes,
        string $html,
    ): string {
        if (null === $linkAttributes || !isset($source->compiledProfile()->inlineLinks[$kind])) {
            return $html;
        }

        return HtmlUrlAttributeRewriter::first(
            $html,
            'a',
            'href',
            HtmlEscaper::attribute($this->url($linkAttributes['destination'] ?? '')),
        );
    }

    private function inlineKindName(InlineMarkdownRenderSource $source, int $kind): string
    {
        return match ($kind) {
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
            default => $source->compiledProfile()->nodeKinds->get($kind)->name,
        };
    }

    private function strongTape(
        InlineMarkdownRenderSource $source,
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $node,
        bool $hardBreaks,
        bool $insideStrong,
        bool $insideLink,
    ): string {
        $children = $this->renderTapeChildren($source, $buffer, $columns, $node, $hardBreaks, true, $insideLink);

        return $insideStrong && $source->compiledProfile()->strikethrough ? $children : '<strong>'.$children.'</strong>';
    }

    /**
     * @param array{destination: string, title?: string|null, alt?: string}|null $linkAttributes
     */
    private function linkTape(
        InlineMarkdownRenderSource $source,
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $node,
        bool $hardBreaks,
        ?array $linkAttributes,
    ): string {
        [$href, $title] = explode("\x00", ($columns->payload[$node] ?? "\x00")."\x00", 3);
        $href = $linkAttributes['destination'] ?? $href;
        $attribute = '' === $title ? '' : ' title="'.HtmlEscaper::attribute($title).'"';

        return '<a href="'.HtmlEscaper::attribute($this->url($href)).'"'.$attribute.'>'.$this->renderTapeChildren($source, $buffer, $columns, $node, $hardBreaks, insideLink: true).'</a>';
    }

    /**
     * @param array{destination: string, title?: string|null, alt?: string}|null $linkAttributes
     */
    private function imageTape(SourceBuffer $buffer, ParseTapeColumns $columns, int $node, ?array $linkAttributes): string
    {
        $parts = explode("\x00", $columns->payload[$node] ?? '', 3);
        $src = $linkAttributes['destination'] ?? ($parts[0] ?? '');
        $title = $parts[1] ?? '';
        $altText = $parts[2] ?? $this->altText($buffer, $columns, $node);
        $attribute = '' === $title ? '' : ' title="'.HtmlEscaper::attribute($title).'"';

        return '<img src="'.HtmlEscaper::attribute($this->url($src)).'" alt="'.HtmlEscaper::attribute($altText).'"'.$attribute.' />';
    }

    /**
     * @param array{destination: string, title?: string|null, alt?: string}|null $linkAttributes
     */
    private function autolinkTape(
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $node,
        ?array $linkAttributes,
    ): string {
        $destination = $linkAttributes['destination'] ?? ($columns->payload[$node] ?? '');

        return '<a href="'.HtmlEscaper::attribute($this->url($destination)).'">'
            .HtmlEscaper::text($buffer->substring($columns->startOffset[$node], $columns->endOffset[$node]))
            .'</a>';
    }

    /**
     * @param array{destination: string, title?: string|null, alt?: string}|null $linkAttributes
     */
    private function decorateLinkOutput(
        InlineMarkdownRenderSource $source,
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $node,
        int $kind,
        string $html,
        bool $insideLink,
        ?array $linkAttributes,
    ): string {
        $decorators = $source->compiledProfile()->htmlLinkDecorators;
        if (null === $decorators) {
            return $html;
        }

        $native = InlineKind::LINK === $kind || InlineKind::AUTOLINK === $kind;
        $custom = !$insideLink && isset($source->compiledProfile()->inlineLinks[$kind]);
        if (!$native && !$custom) {
            return $html;
        }

        return $decorators->decorate(
            $this->inlineNodeContext($source, $buffer, $columns, $node, $kind, $linkAttributes),
            $html,
        );
    }

    /**
     * Applies the URL policy to an emitted destination: a disallowed
     * scheme empties the attribute value and keeps the element.
     */
    private function url(string $href): string
    {
        return $this->policy->filtersUrls && !$this->policy->allowsUrl($href) ? '' : $href;
    }

    private function altText(SourceBuffer $buffer, ParseTapeColumns $columns, int $parent): string
    {
        $text = '';
        $kinds = $columns->kind;
        $starts = $columns->startOffset;
        $ends = $columns->endOffset;
        $payloads = $columns->payload;
        $siblings = $columns->nextSibling;
        $node = $columns->firstChild[$parent];

        while (ParseTape::NONE !== $node) {
            $text .= match ($kinds[$node]) {
                InlineKind::TEXT, InlineKind::CODE_SPAN => $payloads[$node] ?? $buffer->substring($starts[$node], $ends[$node]),
                InlineKind::SOFT_BREAK, InlineKind::HARD_BREAK => "\n",
                InlineKind::AUTOLINK, InlineKind::HTML_INLINE => $buffer->substring($starts[$node], $ends[$node]),
                default => isset($columns->extensionInlineNode[$node])
                    ? $columns->extensionInlineNode[$node]->text
                    : $this->altText($buffer, $columns, $node),
            };
            $node = $siblings[$node];
        }

        return $text;
    }

    private function customTape(InlineMarkdownRenderSource $source, SourceBuffer $buffer, ParseTapeColumns $columns, int $node, bool $insideLink): string
    {
        $kind = $columns->kind[$node];
        $extensionNode = $columns->extensionInlineNode[$node];
        $renderer = $source->compiledProfile()->htmlInlineRenderers[$kind]
            ?? throw new RenderException(\sprintf('No HTML renderer for custom inline kind %d.', $kind));

        if ($insideLink && (isset($source->compiledProfile()->inlineLinks[$kind]) || $renderer instanceof LinkLikeInlineRenderer)) {
            return HtmlEscaper::text($extensionNode->text);
        }

        ++Instrumentation::$extensionInlineRendererLookups;
        $context = $this->customContext($buffer, $columns, $node);

        return $renderer instanceof PlannedHtmlInlineRenderer && null !== $this->documentRenderPlan
            ? $renderer->renderWithPlan($context, $this->documentRenderPlan)
            : $renderer->render($context);
    }

    private function accumulateCustomTape(
        InlineMarkdownRenderSource $source,
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $node,
        string $html,
    ): ?string {
        $renderer = $source->compiledProfile()->htmlInlineRenderers[$columns->kind[$node]] ?? null;

        if (!$renderer instanceof AccumulatingHtmlInlineRenderer) {
            return null;
        }

        ++Instrumentation::$extensionInlineRendererLookups;

        return $renderer->accumulate($this->customContext($buffer, $columns, $node), $html);
    }

    private function customContext(
        SourceBuffer $buffer,
        ParseTapeColumns $columns,
        int $node,
    ): HtmlInlineOutputContext {
        $range = $this->rangesAreOriginal
            ? new \Alto\Markdown\Source\SourceRange($columns->startOffset[$node], $columns->endOffset[$node])
            : null;
        $matchedSource = $columns->payload[$node]
            ?? throw new RenderException(\sprintf('Custom inline node %d has no matched source.', $node));

        return new HtmlInlineOutputContext(
            $columns->extensionInlineNode[$node],
            $range,
            $matchedSource,
            $this->policy,
        );
    }

    private function rawHtml(InlineMarkdownRenderSource $source, string $html): string
    {
        return match ($this->policy->rawHtml) {
            RawHtmlPolicy::Strip => '',
            RawHtmlPolicy::Escape => HtmlEscaper::text($html),
            RawHtmlPolicy::Allow => $this->policy->sanitizesHtml() || !$source->compiledProfile()->tagFilter
                ? $html
                : self::filterRawTags($html),
        };
    }

    /**
     * GFM tagfilter over one raw inline HTML slice, shared with the fused
     * emission lane.
     */
    public static function filterRawTags(string $html): string
    {
        return (string) preg_replace_callback(
            '/<(?=\\/?(?:title|textarea|style|xmp|iframe|noembed|noframes|script|plaintext)(?:\\s|>|\\/))/i',
            static fn (): string => '&lt;',
            $html,
        );
    }
}
