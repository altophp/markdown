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

use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\Block\MarkdownBlockPrinter;
use Alto\Markdown\Extension\Document\CompiledDocumentTransforms;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\Formatter\FormatterPassDefinition;
use Alto\Markdown\Extension\Html\CompiledHtmlDecoratorChain;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\InlineLinkSemantics;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;
use Alto\Markdown\Extension\LinkRewrite\CompiledLinkDestinationRewriter;
use Alto\Markdown\Extension\Lint\LintRuleDefinition;
use Alto\Markdown\Extension\Stats\StatsMetricDefinition;
use Alto\Markdown\Node\Kind\NodeKindRegistry;
use Alto\Markdown\Parser\Block\BlockConstruct;
use Alto\Markdown\Parser\Inline\ContentScannedInlineConstruct;
use Alto\Markdown\Parser\Inline\InlineConstruct;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CompiledProfile
{
    /**
     * @param array<string, true>                    $features
     * @param list<ExtensionInterface>               $extensions
     * @param list<BlockConstruct>                   $blockConstructs
     * @param list<InlineConstruct>                  $inlineConstructs
     * @param list<string>                           $contentScanTriggers
     * @param list<ContentScannedInlineConstruct>    $contentScannedConstructs
     * @param array<string, FormatterPassDefinition> $formatterPasses
     * @param array<string, LintRuleDefinition>      $lintRules
     * @param array<string, StatsMetricDefinition>   $statsMetrics
     * @param array<int, HtmlBlockRenderer>          $htmlBlockRenderers
     * @param array<int, MarkdownBlockPrinter>       $markdownBlockPrinters
     * @param array<int, HtmlInlineRenderer>         $htmlInlineRenderers
     * @param array<int, MarkdownInlinePrinter>      $markdownInlinePrinters
     * @param array<int, CompiledHtmlDecoratorChain> $htmlBlockDecorators
     * @param array<int, CompiledHtmlDecoratorChain> $htmlInlineDecorators
     * @param array<int, InlineLinkSemantics>        $inlineLinks
     */
    public function __construct(
        public string $name,
        private array $features,
        private array $extensions,
        public NodeKindRegistry $nodeKinds,
        private array $blockConstructs,
        private array $inlineConstructs,
        public string $inlineSpecialBytes,
        public string $plainTextSpecialBytes,
        public array $contentScanTriggers,
        public array $contentScannedConstructs,
        public bool $taskListItems,
        public bool $tagFilter,
        public bool $strikethrough,
        public ?int $githubAlertKind,
        public array $formatterPasses,
        public array $lintRules,
        public array $statsMetrics,
        public ?CompiledDocumentTransforms $documentTransforms,
        public array $htmlBlockRenderers,
        public array $markdownBlockPrinters,
        public array $htmlInlineRenderers,
        public array $markdownInlinePrinters,
        public array $htmlBlockDecorators,
        public array $htmlInlineDecorators,
        public ?CompiledLinkDestinationRewriter $linkDestinationRewriter,
        public ?CompiledHtmlDecoratorChain $htmlLinkDecorators,
        public ?CompiledHtmlDecoratorChain $htmlHeadingDecorators,
        public array $inlineLinks,
    ) {
    }

    public function supports(Feature $feature): bool
    {
        return isset($this->features[$feature->value]);
    }

    /**
     * @return list<ExtensionInterface>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /**
     * @return list<BlockConstruct>
     */
    public function blockConstructs(): array
    {
        return $this->blockConstructs;
    }

    /**
     * @return list<InlineConstruct>
     */
    public function inlineConstructs(): array
    {
        return $this->inlineConstructs;
    }
}
