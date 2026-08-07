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

namespace Alto\Markdown\Profile;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\BlockExtensionInterface;
use Alto\Markdown\Extension\CompiledExtension;
use Alto\Markdown\Extension\Document\CompiledDocumentTransforms;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\Document\PlannedHtmlBlockRenderer;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\FeatureExtensionInterface;
use Alto\Markdown\Extension\Formatter\FormatterPassDefinition;
use Alto\Markdown\Extension\FormatterExtensionInterface;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Extension\GitHub\GitHubAlertsExtension;
use Alto\Markdown\Extension\Html\CompiledHtmlDecoratorChain;
use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\InlineExtensionInterface;
use Alto\Markdown\Extension\LinkDestinationRewriterExtensionInterface;
use Alto\Markdown\Extension\LinkRewrite\CompiledLinkDestinationRewriter;
use Alto\Markdown\Extension\LinkRewrite\LinkDestinationRewriter;
use Alto\Markdown\Extension\Lint\LintRuleDefinition;
use Alto\Markdown\Extension\LintExtensionInterface;
use Alto\Markdown\Extension\NativeBlockOutputExtensionInterface;
use Alto\Markdown\Extension\NativeInlineOutputExtensionInterface;
use Alto\Markdown\Extension\NodeKindBindableExtensionInterface;
use Alto\Markdown\Extension\NodeKindBindings;
use Alto\Markdown\Extension\Stats\StatsMetricDefinition;
use Alto\Markdown\Extension\StatsExtensionInterface;
use Alto\Markdown\Extension\SyntaxExtensionInterface;
use Alto\Markdown\Node\Kind\DefaultNodeKindRegistry;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Node\Kind\NodeKindRegistry;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\Block\PublicBlockParserAdapter;
use Alto\Markdown\Parser\Inline\ContentScannedInlineConstruct;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Inline\PublicInlineParserAdapter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ProfileCompiler
{
    private static ?CompiledProfile $commonmark = null;

    public static function commonmark(): CompiledProfile
    {
        return self::$commonmark ??= new self()->compile(new CommonMarkProfile());
    }

    public function compile(Profile $profile): CompiledProfile
    {
        $registry = new DefaultNodeKindRegistry();
        $features = [];
        $compiledExtensions = [];
        $blockConstructs = [];
        $inlineConstructs = [];
        $inlineSpecials = "\n*_[]!";
        $plainSpecials = "\n*_[]!";
        $contentScanTriggers = [];
        $contentScannedConstructs = [];
        $formatterPasses = [];
        $lintRules = [];
        $statsMetrics = [];
        $documentTransformDefinitions = [];
        $documentTransformIds = [];
        $htmlBlockRenderers = [];
        $markdownBlockPrinters = [];
        $htmlInlineRenderers = [];
        $markdownInlinePrinters = [];
        $publicInlineTriggers = [];
        $inlineLinks = [];
        $htmlDecoratorDefinitions = [];
        $linkDestinationRewriters = [];
        $extensionOrder = 0;

        foreach ($profile->extensions() as $extension) {
            $publicBlocks = $this->publicBlocks($extension);
            $publicInlines = $this->publicInlines($extension);
            $publicDocumentTransforms = $this->documentTransforms($extension);

            foreach ($publicBlocks as $definition) {
                if ($definition->html instanceof PlannedHtmlBlockRenderer && [] === $publicDocumentTransforms) {
                    throw new InvalidExtensionException(\sprintf('Extension "%s" planned HTML block renderer requires a document transform.', $extension->name()));
                }
            }

            $nodeKinds = $this->reserveNodeKinds($registry, $extension, $publicBlocks, $publicInlines);
            $bindings = new NodeKindBindings($nodeKinds);
            $compiledExtension = $extension instanceof NodeKindBindableExtensionInterface
                ? $extension->bindNodeKinds($bindings)
                : new CompiledExtension($extension->name(), $this->features($extension), $nodeKinds);

            if ($extension instanceof FeatureExtensionInterface) {
                foreach ($extension->features() as $feature) {
                    $features[$feature->value] = true;
                }
            }

            $syntax = $compiledExtension instanceof SyntaxExtensionInterface ? $compiledExtension : $extension;

            if ($syntax instanceof SyntaxExtensionInterface) {
                array_push($blockConstructs, ...$syntax->blockConstructs());

                foreach ($syntax->inlineConstructs() as $construct) {
                    $inlineConstructs[] = $construct;

                    // A content-scanned construct over-approximates its start
                    // bytes, so neither special set takes them: the scanners
                    // locate it through its content triggers instead.
                    if ($construct instanceof ContentScannedInlineConstruct) {
                        $contentScannedConstructs[] = $construct;

                        foreach ($construct->contentScanTriggers() as $trigger) {
                            if (!\in_array($trigger, $contentScanTriggers, true)) {
                                $contentScanTriggers[] = $trigger;
                            }
                        }

                        continue;
                    }

                    foreach (str_split($construct->triggerBytes()) as $byte) {
                        if (!str_contains($inlineSpecials, $byte)) {
                            $inlineSpecials .= $byte;
                        }
                        if (!str_contains($plainSpecials, $byte)) {
                            $plainSpecials .= $byte;
                        }
                    }
                }
            }

            if ($compiledExtension instanceof NativeInlineOutputExtensionInterface) {
                foreach ($compiledExtension->nativeHtmlInlineRenderers() as $kind => $renderer) {
                    $htmlInlineRenderers[$kind] = $renderer;
                }
                foreach ($compiledExtension->nativeMarkdownInlinePrinters() as $kind => $printer) {
                    $markdownInlinePrinters[$kind] = $printer;
                }
            }

            if ($compiledExtension instanceof NativeBlockOutputExtensionInterface) {
                foreach ($compiledExtension->nativeHtmlBlockRenderers() as $kind => $renderer) {
                    $htmlBlockRenderers[$kind] = $renderer;
                }
                foreach ($compiledExtension->nativeMarkdownBlockPrinters() as $kind => $printer) {
                    $markdownBlockPrinters[$kind] = $printer;
                }
            }

            foreach ($publicBlocks as $definition) {
                $kind = $bindings->get($extension->name().':'.$definition->kind);
                $blockConstructs[] = new PublicBlockParserAdapter($kind->id, $definition->parser);

                if (null !== $definition->html) {
                    $htmlBlockRenderers[$kind->id] = $definition->html;
                }
                if (null !== $definition->markdown) {
                    $markdownBlockPrinters[$kind->id] = $definition->markdown;
                }
            }

            foreach ($publicInlines as $definition) {
                $kind = $bindings->get($extension->name().':'.$definition->kind);
                $trigger = $definition->parser->triggerByte();
                PublicInlineParserAdapter::validateTrigger($trigger);
                $inlineConstructs[] = new PublicInlineParserAdapter($kind->id, $definition->parser, $trigger);
                $htmlInlineRenderers[$kind->id] = $definition->html;
                $markdownInlinePrinters[$kind->id] = $definition->markdown;
                $publicInlineTriggers[] = $trigger;

                if (null !== $definition->link) {
                    $inlineLinks[$kind->id] = $definition->link;
                }

                if (!str_contains($inlineSpecials, $trigger)) {
                    $inlineSpecials .= $trigger;
                }
                if (!str_contains($plainSpecials, $trigger)) {
                    $plainSpecials .= $trigger;
                }
            }

            foreach ($this->lintRules($extension) as $definition) {
                $id = $extension->name().':'.$definition->name;

                if (isset($lintRules[$id])) {
                    throw new InvalidExtensionException(\sprintf('Duplicate lint rule "%s".', $id));
                }

                $lintRules[$id] = $definition;
            }

            foreach ($this->formatterPasses($extension) as $definition) {
                $id = $extension->name().':'.$definition->name;

                if (isset($formatterPasses[$id])) {
                    throw new InvalidExtensionException(\sprintf('Duplicate formatter pass "%s".', $id));
                }

                $formatterPasses[$id] = $definition;
            }

            foreach ($this->statsMetrics($extension) as $definition) {
                $id = $extension->name().':'.$definition->name;

                if (isset($statsMetrics[$id])) {
                    throw new InvalidExtensionException(\sprintf('Duplicate stats metric "%s".', $id));
                }

                $statsMetrics[$id] = $definition;
            }

            $documentTransformOrder = 0;

            foreach ($publicDocumentTransforms as $definition) {
                $id = $extension->name().':'.$definition->name;

                if (isset($documentTransformIds[$id])) {
                    throw new InvalidExtensionException(\sprintf('Duplicate document transform "%s".', $id));
                }

                $documentTransformIds[$id] = true;
                $documentTransformDefinitions[] = [
                    $definition,
                    $extensionOrder,
                    $documentTransformOrder,
                ];
                ++$documentTransformOrder;
            }

            $definitionOrder = 0;

            foreach ($this->htmlDecorators($extension) as $definition) {
                $htmlDecoratorDefinitions[] = [
                    $definition,
                    $extensionOrder,
                    $definitionOrder,
                ];
                ++$definitionOrder;
            }

            array_push($linkDestinationRewriters, ...$this->linkDestinationRewriters($extension));
            $compiledExtensions[] = $compiledExtension;
            ++$extensionOrder;
        }

        if (isset($features[Feature::Strikethrough->value])) {
            foreach ($publicInlineTriggers as $trigger) {
                PublicInlineParserAdapter::validateTrigger($trigger, true);
            }
        }

        uksort(
            $formatterPasses,
            static fn (string $left, string $right): int => [
                $formatterPasses[$left]->order,
                $left,
            ] <=> [
                $formatterPasses[$right]->order,
                $right,
            ],
        );
        ksort($statsMetrics);

        if (isset($features[Feature::Strikethrough->value]) && !str_contains($inlineSpecials, '~')) {
            $inlineSpecials .= '~';
        }
        if (isset($features[Feature::Strikethrough->value]) && !str_contains($plainSpecials, '~')) {
            $plainSpecials .= '~';
        }

        [$htmlBlockDecorators, $htmlInlineDecorators, $htmlLinkDecorators, $htmlHeadingDecorators] = $this->compileHtmlDecorators(
            $registry,
            $htmlDecoratorDefinitions,
            isset($features[Feature::Strikethrough->value]),
            $htmlBlockRenderers,
            $htmlInlineRenderers,
        );

        return new CompiledProfile(
            $profile->name(),
            $features,
            $compiledExtensions,
            $registry,
            $blockConstructs,
            $inlineConstructs,
            $inlineSpecials,
            $plainSpecials,
            $contentScanTriggers,
            $contentScannedConstructs,
            isset($features[Feature::TaskLists->value]),
            isset($features[Feature::TagFilter->value]),
            isset($features[Feature::Strikethrough->value]),
            isset($features[Feature::GitHubAlerts->value])
                ? $registry->find(GitHubAlertsExtension::ALERT_KIND)?->id
                : null,
            $formatterPasses,
            $lintRules,
            $statsMetrics,
            [] === $documentTransformDefinitions
                ? null
                : new CompiledDocumentTransforms($documentTransformDefinitions),
            $htmlBlockRenderers,
            $markdownBlockPrinters,
            $htmlInlineRenderers,
            $markdownInlinePrinters,
            $htmlBlockDecorators,
            $htmlInlineDecorators,
            [] === $linkDestinationRewriters
                ? null
                : new CompiledLinkDestinationRewriter($linkDestinationRewriters),
            $htmlLinkDecorators,
            $htmlHeadingDecorators,
            $inlineLinks,
        );
    }

    /**
     * @param list<BlockDefinition>  $publicBlocks
     * @param list<InlineDefinition> $publicInlines
     *
     * @return list<NodeKind>
     */
    private function reserveNodeKinds(
        NodeKindRegistry $registry,
        ExtensionInterface $extension,
        array $publicBlocks,
        array $publicInlines,
    ): array {
        $localNames = [];

        if ($extension instanceof SyntaxExtensionInterface || $extension instanceof NodeKindBindableExtensionInterface) {
            array_push($localNames, ...$extension->nodeKindNames());
        }

        foreach ($publicBlocks as $definition) {
            $localNames[] = $definition->kind;
        }
        foreach ($publicInlines as $definition) {
            $localNames[] = $definition->kind;
        }

        $nodeKinds = [];
        $seen = [];

        foreach ($localNames as $localName) {
            if (isset($seen[$localName])) {
                throw new InvalidExtensionException(\sprintf('Duplicate node kind "%s:%s".', $extension->name(), $localName));
            }

            $seen[$localName] = true;
            $nodeKinds[] = $registry->reserve($extension->name(), $localName);
        }

        return $nodeKinds;
    }

    /**
     * @return list<Feature>
     */
    private function features(ExtensionInterface $extension): array
    {
        if (!$extension instanceof FeatureExtensionInterface) {
            return [];
        }

        $features = [];

        foreach ($extension->features() as $feature) {
            $features[] = $feature;
        }

        return $features;
    }

    /**
     * @return list<BlockDefinition>
     */
    private function publicBlocks(ExtensionInterface $extension): array
    {
        if (!$extension instanceof BlockExtensionInterface) {
            return [];
        }

        $definitions = [];

        foreach ($extension->blocks() as $definition) {
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return list<InlineDefinition>
     */
    private function publicInlines(ExtensionInterface $extension): array
    {
        if (!$extension instanceof InlineExtensionInterface) {
            return [];
        }

        $definitions = [];

        foreach ($extension->inlines() as $definition) {
            if (!$definition instanceof InlineDefinition) {
                throw new InvalidExtensionException(\sprintf('Extension "%s" inlines() must yield %s values.', $extension->name(), InlineDefinition::class));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return list<LintRuleDefinition>
     */
    private function lintRules(ExtensionInterface $extension): array
    {
        if (!$extension instanceof LintExtensionInterface) {
            return [];
        }

        $definitions = [];

        foreach ($extension->lintRules() as $definition) {
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return list<FormatterPassDefinition>
     */
    private function formatterPasses(ExtensionInterface $extension): array
    {
        if (!$extension instanceof FormatterExtensionInterface) {
            return [];
        }

        $definitions = [];

        foreach ($extension->formatterPasses() as $definition) {
            if (!$definition instanceof FormatterPassDefinition) {
                throw new InvalidExtensionException(\sprintf('Extension "%s" formatterPasses() must yield %s values.', $extension->name(), FormatterPassDefinition::class));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return list<StatsMetricDefinition>
     */
    private function statsMetrics(ExtensionInterface $extension): array
    {
        if (!$extension instanceof StatsExtensionInterface) {
            return [];
        }

        $definitions = [];

        foreach ($extension->statsMetrics() as $definition) {
            if (!$definition instanceof StatsMetricDefinition) {
                throw new InvalidExtensionException(\sprintf('Extension "%s" statsMetrics() must yield %s values.', $extension->name(), StatsMetricDefinition::class));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return list<DocumentTransformDefinition>
     */
    private function documentTransforms(ExtensionInterface $extension): array
    {
        if (!$extension instanceof DocumentTransformExtensionInterface) {
            return [];
        }

        $definitions = [];

        foreach ($extension->documentTransforms() as $definition) {
            if (!$definition instanceof DocumentTransformDefinition) {
                throw new InvalidExtensionException(\sprintf('Extension "%s" documentTransforms() must yield %s values.', $extension->name(), DocumentTransformDefinition::class));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return list<HtmlDecoratorDefinition>
     */
    private function htmlDecorators(ExtensionInterface $extension): array
    {
        if (!$extension instanceof HtmlDecoratorExtensionInterface) {
            return [];
        }

        $definitions = [];

        foreach ($extension->htmlDecorators() as $definition) {
            if (!$definition instanceof HtmlDecoratorDefinition) {
                throw new InvalidExtensionException(\sprintf('Extension "%s" htmlDecorators() must yield %s values.', $extension->name(), HtmlDecoratorDefinition::class));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return list<LinkDestinationRewriter>
     */
    private function linkDestinationRewriters(ExtensionInterface $extension): array
    {
        if (!$extension instanceof LinkDestinationRewriterExtensionInterface) {
            return [];
        }

        $rewriters = [];

        foreach ($extension->linkDestinationRewriters() as $rewriter) {
            if (!$rewriter instanceof LinkDestinationRewriter) {
                throw new InvalidExtensionException(\sprintf('Extension "%s" linkDestinationRewriters() must yield %s values.', $extension->name(), LinkDestinationRewriter::class));
            }

            $rewriters[] = $rewriter;
        }

        return $rewriters;
    }

    /**
     * @param list<array{HtmlDecoratorDefinition, int, int}> $definitions
     * @param array<int, HtmlBlockRenderer>                  $htmlBlockRenderers
     * @param array<int, HtmlInlineRenderer>                 $htmlInlineRenderers
     *
     * @return array{
     *     array<int, CompiledHtmlDecoratorChain>,
     *     array<int, CompiledHtmlDecoratorChain>,
     *     ?CompiledHtmlDecoratorChain,
     *     ?CompiledHtmlDecoratorChain
     * }
     */
    private function compileHtmlDecorators(
        NodeKindRegistry $registry,
        array $definitions,
        bool $strikethrough,
        array $htmlBlockRenderers,
        array $htmlInlineRenderers,
    ): array {
        /**
         * @var array<string, array{int, 'block'|'inline'}>
         */
        $nativeKinds = [
            'paragraph' => [BlockKind::PARAGRAPH, 'block'],
            'atx-heading' => [BlockKind::ATX_HEADING, 'block'],
            'setext-heading' => [BlockKind::SETEXT_HEADING, 'block'],
            'indented-code' => [BlockKind::INDENTED_CODE, 'block'],
            'fenced-code' => [BlockKind::FENCED_CODE, 'block'],
            'html-block' => [BlockKind::HTML_BLOCK, 'block'],
            'block-quote' => [BlockKind::BLOCK_QUOTE, 'block'],
            'list' => [BlockKind::LIST, 'block'],
            'list-item' => [BlockKind::LIST_ITEM, 'block'],
            'thematic-break' => [BlockKind::THEMATIC_BREAK, 'block'],
            'text' => [InlineKind::TEXT, 'inline'],
            'soft-break' => [InlineKind::SOFT_BREAK, 'inline'],
            'hard-break' => [InlineKind::HARD_BREAK, 'inline'],
            'code-span' => [InlineKind::CODE_SPAN, 'inline'],
            'emphasis' => [InlineKind::EMPHASIS, 'inline'],
            'strong' => [InlineKind::STRONG, 'inline'],
            'link' => [InlineKind::LINK, 'inline'],
            'image' => [InlineKind::IMAGE, 'inline'],
            'autolink' => [InlineKind::AUTOLINK, 'inline'],
            'html-inline' => [InlineKind::HTML_INLINE, 'inline'],
        ];

        if ($strikethrough) {
            $nativeKinds['strikethrough'] = [InlineKind::STRIKETHROUGH, 'inline'];
        }

        foreach ([GfmExtension::TABLE_KIND, GitHubAlertsExtension::ALERT_KIND] as $blockKind) {
            $nodeKind = $registry->find($blockKind);

            if (null !== $nodeKind) {
                $nativeKinds[$blockKind] = [$nodeKind->id, 'block'];
            }
        }

        foreach (array_keys($htmlBlockRenderers) as $kindId) {
            $nativeKinds[$registry->get($kindId)->name] = [$kindId, 'block'];
        }

        foreach (array_keys($htmlInlineRenderers) as $kindId) {
            $nativeKinds[$registry->get($kindId)->name] = [$kindId, 'inline'];
        }

        /**
         * @var array<'block'|'inline', array<int, list<array{HtmlDecoratorDefinition, int, int}>>>
         */
        $grouped = ['block' => [], 'inline' => []];
        $linkLike = [];
        $headings = [];
        $blocks = [];

        foreach ($definitions as $entry) {
            [$definition] = $entry;

            if ($definition->targetsLinkLike()) {
                $linkLike[] = $entry;

                continue;
            }

            if ($definition->targetsHeadings()) {
                $headings[] = $entry;

                continue;
            }

            if ($definition->targetsBlocks()) {
                $blocks[] = $entry;

                continue;
            }

            $kind = $definition->nodeKind();
            $target = null === $kind ? null : ($nativeKinds[$kind] ?? null);

            if (null === $target) {
                throw new InvalidExtensionException(\sprintf('HTML decorator targets unknown or non-native node kind "%s".', $kind));
            }

            [$kindId, $category] = $target;
            $grouped[$category][$kindId][] = $entry;
        }

        $compiled = ['block' => [], 'inline' => []];

        foreach ($grouped as $category => $byKind) {
            if ('block' === $category && [] !== $blocks) {
                foreach ($nativeKinds as [$kindId, $nativeCategory]) {
                    if ('block' === $nativeCategory) {
                        $byKind[$kindId] ??= [];
                        array_push($byKind[$kindId], ...$blocks);
                    }
                }
            }

            foreach ($byKind as $kindId => $entries) {
                $compiled[$category][$kindId] = $this->compileHtmlDecoratorChain($entries);
            }
        }

        return [
            $compiled['block'],
            $compiled['inline'],
            [] === $linkLike ? null : $this->compileHtmlDecoratorChain($linkLike),
            [] === $headings ? null : $this->compileHtmlDecoratorChain($headings),
        ];
    }

    /**
     * @param list<array{HtmlDecoratorDefinition, int, int}> $entries
     */
    private function compileHtmlDecoratorChain(array $entries): CompiledHtmlDecoratorChain
    {
        usort(
            $entries,
            static fn (array $left, array $right): int => [
                $left[0]->priority,
                $left[1],
                $left[2],
            ] <=> [
                $right[0]->priority,
                $right[1],
                $right[2],
            ],
        );

        return new CompiledHtmlDecoratorChain(array_map(
            static fn (array $entry): \Alto\Markdown\Extension\Html\HtmlNodeDecorator => $entry[0]->decorator,
            $entries,
        ));
    }
}
