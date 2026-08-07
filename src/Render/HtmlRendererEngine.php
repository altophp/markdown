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
use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\DescriptionList\DescriptionListExtension;
use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Document\PlannedHtmlBlockRenderer;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Extension\Html\CompiledHtmlDecoratorChain;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ParseTapeColumns;
use Alto\Markdown\Parser\SyntaxParser;

/**
 * Shared HTML traversal over the minimal render-source contract.
 *
 * The walk grabs one copy-on-write column snapshot per entry point and
 * indexes the tape arrays directly: rendering never mutates the block tape,
 * so the snapshot cannot go stale during a render. Externally supplied entry
 * ordinals are validated once through a checked accessor; every ordinal
 * inside the walk comes from the tape's own link columns.
 *
 * Container blocks are expanded with an explicit frame stack
 * ({@see HtmlBlockFrame}) rather than recursion, so a deeply nested document
 * (for example 20,000 nested blockquotes) is bounded by heap, not by the
 * native C stack. Leaf blocks stay on the direct dispatch path.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HtmlRendererEngine
{
    /**
     * Native-recursion budget before the walk hands off to the explicit
     * frame stack. Ordinary documents nest far below this, so they keep the
     * cheap recursive path; only a pathologically deep container pays the
     * heap-bound iteration that keeps the C stack safe.
     */
    private const int STACK_LIMIT = 500;

    private HtmlPolicy $policy;

    /**
     * The source's root ordinal, read once per entry point. Leaf rendering
     * compares a block's parent against it to tell a root-level opaque leaf
     * from a container-nested one, and that test must not cost a call per
     * block.
     */
    private int $rootOrdinal = ParseTape::NONE;

    private ?DocumentRenderPlan $documentRenderPlan = null;

    /**
     * @var array<int, HtmlBlockRenderer>
     */
    private array $blockRenderers = [];

    /**
     * @var array<int, CompiledHtmlDecoratorChain>
     */
    private array $blockDecorators = [];

    public function __construct(private readonly BlockInlineRenderer $inline = new HtmlInlineRenderer())
    {
        $this->policy = HtmlPolicy::safe();
    }

    public function renderDocument(HtmlRenderSource $source, HtmlPolicy $policy): string
    {
        return $this->finalizeHtml($this->renderDocumentFragment($source, $policy));
    }

    private function renderDocumentFragment(HtmlRenderSource $source, HtmlPolicy $policy): string
    {
        $this->documentRenderPlan = null;
        $this->policy = $policy;
        $this->rootOrdinal = $source->htmlRootOrdinal();
        $this->configureBlockHandlers($source);
        $columns = $this->projectDocumentTransforms($source, $source->htmlTape()->columns(), true);
        $this->inline->useDocumentRenderPlan($this->documentRenderPlan);

        if (Instrumentation::$timing) {
            Instrumentation::enter('render-output');
        }

        $html = $this->renderDocumentChildren($source, $columns);

        if (Instrumentation::$timing) {
            Instrumentation::leave('render-output');
        }

        return $this->documentRenderPlan?->finalizeHtml($html) ?? $html;
    }

    private function renderDocumentChildren(HtmlRenderSource $source, ParseTapeColumns $columns): string
    {
        if (null === $this->documentRenderPlan || !$this->documentRenderPlan->hasRootHtmlLayout()) {
            return $this->renderChildren($source, $columns, $source->htmlRootOrdinal(), false, 0);
        }

        $html = '';
        $child = $columns->firstChild[$source->htmlRootOrdinal()];

        while (ParseTape::NONE !== $child) {
            $html .= $this->documentRenderPlan->htmlBeforeRootBlock($child)
                .$this->renderBlock($source, $columns, $child, false, 0);
            $child = $columns->nextSibling[$child];
        }

        return $html.$this->documentRenderPlan->htmlAfterRootBlocks();
    }

    public function renderInlineMarkdown(InlineMarkdownRenderSource $source, string $markdown, HtmlPolicy $policy): string
    {
        $this->documentRenderPlan = null;
        $this->policy = $policy;
        $this->inline->useDocumentRenderPlan(null);

        if (Instrumentation::$timing) {
            Instrumentation::enter('render-output');
        }

        $html = $this->inline->renderMarkdown($source, $markdown, $policy);

        if (Instrumentation::$timing) {
            Instrumentation::leave('render-output');
        }

        return $this->finalizeHtml($html);
    }

    public function renderNode(HtmlRenderSource $source, int $ordinal, HtmlPolicy $policy): string
    {
        $this->documentRenderPlan = null;
        $this->policy = $policy;
        $this->rootOrdinal = $source->htmlRootOrdinal();
        $this->configureBlockHandlers($source);
        $tape = $source->htmlTape();
        $tape->kindId($ordinal);
        $columns = $this->projectDocumentTransforms($source, $tape->columns(), false);
        $this->inline->useDocumentRenderPlan($this->documentRenderPlan);

        $html = $this->renderBlock($source, $columns, $ordinal, false, 0);

        return $this->finalizeHtml($this->documentRenderPlan?->finalizeHtml($html) ?? $html);
    }

    public function renderSection(HtmlRenderSource $source, int $ordinal, int $endOffset, HtmlPolicy $policy): string
    {
        $this->documentRenderPlan = null;
        $this->policy = $policy;
        $this->rootOrdinal = $source->htmlRootOrdinal();
        $this->configureBlockHandlers($source);
        $tape = $source->htmlTape();
        $columns = $this->projectDocumentTransforms($source, $tape->columns(), false);
        $this->inline->useDocumentRenderPlan($this->documentRenderPlan);

        if (ParseTape::NONE === $ordinal || $tape->startOffset($ordinal) >= $endOffset) {
            return '';
        }

        $html = '';

        while (ParseTape::NONE !== $ordinal && $columns->startOffset[$ordinal] < $endOffset) {
            $html .= $this->renderBlock($source, $columns, $ordinal, false, 0);
            $ordinal = $columns->nextSibling[$ordinal];
        }

        return $this->finalizeHtml($this->documentRenderPlan?->finalizeHtml($html) ?? $html);
    }

    private function renderChildren(HtmlRenderSource $source, ParseTapeColumns $columns, int $parent, bool $tight, int $depth): string
    {
        $html = '';
        $child = $columns->firstChild[$parent];

        while (ParseTape::NONE !== $child) {
            $html .= $this->renderBlock($source, $columns, $child, $tight, $depth);
            $child = $columns->nextSibling[$child];
        }

        return $html;
    }

    private function renderBlock(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal, bool $tight, int $depth): string
    {
        $kind = $this->kindName($source, $columns, $ordinal);
        $kindId = $columns->kind[$ordinal];
        $renderer = $this->blockRenderers[$kindId] ?? null;
        $decorator = $this->blockDecorators[$kindId] ?? null;

        if ($depth >= self::STACK_LIMIT && (null !== $renderer || $this->isContainerKind($kind))) {
            return $this->renderContainer($source, $columns, $ordinal, $tight, $kind);
        }

        $next = $depth + 1;

        if (null !== $renderer) {
            $context = $this->blockOutputContext($source, $columns, $ordinal);
            $children = $this->renderChildren($source, $columns, $ordinal, false, $next);
            $html = $this->renderCustomBlock($renderer, $context, $children);

            return null === $decorator
                ? $html
                : $decorator->decorate(
                    $this->htmlNodeContext($source, $columns, $ordinal, $kind),
                    $html,
                    $this->documentRenderPlan,
                );
        }

        if (null !== $decorator) {
            $codeAttributes = ('indented-code' === $kind || 'fenced-code' === $kind)
                ? $this->codeBlockAttributes($source, $ordinal, $kind)
                : null;
            $html = match ($kind) {
                'paragraph' => $tight ? $this->paragraphContent($source, $ordinal)."\n" : '<p>'.$this->paragraphContent($source, $ordinal)."</p>\n",
                'atx-heading' => $this->heading($source, $columns, $ordinal, 'atx-heading', false),
                'setext-heading' => $this->heading($source, $columns, $ordinal, 'setext-heading', true),
                'thematic-break' => "<hr />\n",
                'indented-code', 'fenced-code' => $this->codeBlock($codeAttributes ?? throw new \LogicException('Missing code-block attributes.')),
                'html-block' => $this->rawBlockHtml($source, $columns, $ordinal),
                'block-quote' => "<blockquote>\n".$this->renderChildren($source, $columns, $ordinal, false, $next)."</blockquote>\n",
                'github:alert' => $this->alert($source, $columns, $ordinal, $next),
                'list' => $this->list($source, $columns, $ordinal, $next),
                'list-item' => $this->listItem($source, $columns, $ordinal, $tight, $next),
                DescriptionListExtension::LIST_KIND => "<dl>\n".$this->renderChildren($source, $columns, $ordinal, false, $next)."</dl>\n",
                DescriptionListExtension::TERM_KIND => '<dt>'.$this->inline->renderBlockInlines($source, $ordinal, true, $this->policy)."</dt>\n",
                DescriptionListExtension::DESCRIPTION_KIND => $this->description($source, $columns, $ordinal, $next),
                GfmExtension::TABLE_KIND => $this->table($source, $ordinal),
                default => throw new RenderException(\sprintf('No HTML decorator target for node kind "%s".', $kind)),
            };

            return $decorator->decorate(
                $this->htmlNodeContext($source, $columns, $ordinal, $kind, $codeAttributes),
                $html,
                $this->documentRenderPlan,
            );
        }

        return match ($kind) {
            'paragraph' => $tight ? $this->paragraphContent($source, $ordinal)."\n" : '<p>'.$this->paragraphContent($source, $ordinal)."</p>\n",
            'atx-heading' => $this->heading($source, $columns, $ordinal, 'atx-heading', false),
            'setext-heading' => $this->heading($source, $columns, $ordinal, 'setext-heading', true),
            'thematic-break' => "<hr />\n",
            'indented-code', 'fenced-code' => $this->codeBlock($this->codeBlockAttributes($source, $ordinal, $kind)),
            'html-block' => $this->rawBlockHtml($source, $columns, $ordinal),
            'block-quote' => "<blockquote>\n".$this->renderChildren($source, $columns, $ordinal, false, $next)."</blockquote>\n",
            'github:alert' => $this->alert($source, $columns, $ordinal, $next),
            'list' => $this->list($source, $columns, $ordinal, $next),
            'list-item' => $this->listItem($source, $columns, $ordinal, $tight, $next),
            DescriptionListExtension::LIST_KIND => "<dl>\n".$this->renderChildren($source, $columns, $ordinal, false, $next)."</dl>\n",
            DescriptionListExtension::TERM_KIND => '<dt>'.$this->inline->renderBlockInlines($source, $ordinal, true, $this->policy)."</dt>\n",
            DescriptionListExtension::DESCRIPTION_KIND => $this->description($source, $columns, $ordinal, $next),
            'link-reference-definition' => '',
            // Front matter is document metadata, never HTML output.
            'frontmatter:block' => '',
            GfmExtension::TABLE_KIND => $this->table($source, $ordinal),
            default => throw new RenderException(\sprintf('No HTML renderer for node kind "%s".', $kind)),
        };
    }

    private function alert(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal, int $depth): string
    {
        return $this->alertOpen($columns, $ordinal)
            .$this->renderChildren($source, $columns, $ordinal, false, $depth)
            ."</div>\n";
    }

    private function list(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal, int $depth): string
    {
        $flags = $columns->flags[$ordinal];
        $items = $this->renderChildren($source, $columns, $ordinal, 0 === ($flags & 2), $depth);

        if (0 !== ($flags & 1)) {
            $start = (int) ($columns->payload[$ordinal] ?? '1');
            $attribute = 1 === $start ? '' : ' start="'.$start.'"';

            return '<ol'.$attribute.">\n".$items."</ol>\n";
        }

        return "<ul>\n".$items."</ul>\n";
    }

    private function listItem(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal, bool $tight, int $depth): string
    {
        $parts = [];
        $firstIsInline = false;
        $lastIsInline = false;
        $first = true;
        $task = $this->taskCheckbox($columns, $ordinal);
        $child = $columns->firstChild[$ordinal];

        while (ParseTape::NONE !== $child) {
            if ($tight && 'paragraph' === $this->kindName($source, $columns, $child)) {
                $parts[] = ($first ? $task : '').$this->paragraphContent($source, $child);
                $lastIsInline = true;

                if ($first) {
                    $task = '';
                    $firstIsInline = true;
                }
            } elseif ($first && '' !== $task && 'paragraph' === $this->kindName($source, $columns, $child)) {
                // The task marker is inline content of the item's first
                // paragraph, so a loose item keeps it inside the <p>.
                $parts[] = '<p>'.$task.$this->paragraphContent($source, $child).'</p>';
                $task = '';
                $lastIsInline = false;
            } else {
                $rendered = rtrim($this->renderBlock($source, $columns, $child, $tight, $depth), "\n");

                if ('' !== $rendered) {
                    $parts[] = $rendered;
                    $lastIsInline = false;
                }
            }

            $first = false;
            $child = $columns->nextSibling[$child];
        }

        if ([] === $parts) {
            return "<li></li>\n";
        }

        $prefix = $firstIsInline ? '' : "\n";
        $suffix = $lastIsInline ? '' : "\n";

        return '<li>'.$prefix.$task.implode("\n", $parts).$suffix."</li>\n";
    }

    private function description(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal, int $depth): string
    {
        $tight = 0 !== ($columns->flags[$ordinal] & 1);
        $parts = [];
        $firstIsInline = false;
        $lastIsInline = false;
        $first = true;
        $child = $columns->firstChild[$ordinal];

        while (ParseTape::NONE !== $child) {
            if ($tight && 'paragraph' === $this->kindName($source, $columns, $child)) {
                $parts[] = $this->paragraphContent($source, $child);
                $firstIsInline = $first;
                $lastIsInline = true;
            } else {
                $rendered = rtrim($this->renderBlock($source, $columns, $child, false, $depth), "\n");

                if ('' !== $rendered) {
                    $parts[] = $rendered;
                    $lastIsInline = false;
                }
            }

            $first = false;
            $child = $columns->nextSibling[$child];
        }

        if ([] === $parts) {
            return "<dd></dd>\n";
        }

        $prefix = $firstIsInline ? '' : "\n";
        $suffix = $lastIsInline ? '' : "\n";

        return '<dd>'.$prefix.implode("\n", $parts).$suffix."</dd>\n";
    }

    /**
     * Expands one deep container block (and every descendant) with an explicit
     * frame stack once native recursion has reached {@see STACK_LIMIT}. Leaf
     * children route back through {@see renderBlock}; container children push a
     * new frame instead of recursing, so native stack depth stays bounded.
     */
    private function renderContainer(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal, bool $tight, string $kind): string
    {
        $stack = [$this->makeFrame($source, $columns, $ordinal, $kind, $tight)];
        $result = '';

        while (true) {
            $top = $stack[array_key_last($stack)];
            $cursor = $top->cursor;

            if (ParseTape::NONE === $cursor) {
                $string = $this->isItemFrame($top)
                    ? $this->finishItem($top)
                    : (HtmlBlockFrame::CUSTOM === $top->kind
                        ? $this->renderCustomBlock(
                            $top->customRenderer ?? throw new \LogicException('Missing custom block renderer.'),
                            $top->customContext ?? throw new \LogicException('Missing custom block output context.'),
                            $top->buf,
                        )
                        : $top->buf.$top->suffix);

                if (null !== $top->decorator) {
                    $string = $top->decorator->decorate(
                        $top->decoratorContext ?? throw new \LogicException('Missing HTML decorator context.'),
                        $string,
                        $this->documentRenderPlan,
                    );
                }

                array_pop($stack);

                if ([] === $stack) {
                    $result = $string;

                    break;
                }

                $parent = $stack[array_key_last($stack)];

                if ($this->isItemFrame($parent)) {
                    $this->deliverItemBlock($parent, $string);
                } else {
                    $parent->buf .= $string;
                }

                continue;
            }

            $top->cursor = $columns->nextSibling[$cursor];
            $childKind = $this->kindName($source, $columns, $cursor);
            $childTight = $top->childTight;

            if ($this->isItemFrame($top)) {
                if ($childTight && 'paragraph' === $childKind) {
                    $top->parts[] = ($top->first ? $top->task : '').$this->paragraphContent($source, $cursor);
                    $top->lastIsInline = true;

                    if ($top->first) {
                        $top->task = '';
                        $top->firstIsInline = true;
                    }

                    $top->first = false;

                    continue;
                }

                if ($top->first && '' !== $top->task && 'paragraph' === $childKind) {
                    // Same rule as the recursive path: a loose task item
                    // carries its marker inside the first paragraph.
                    $top->parts[] = '<p>'.$top->task.$this->paragraphContent($source, $cursor).'</p>';
                    $top->task = '';
                    $top->lastIsInline = false;
                    $top->first = false;

                    continue;
                }

                $top->first = false;

                if ($this->isContainer($columns, $cursor, $childKind)) {
                    $stack[] = $this->makeFrame($source, $columns, $cursor, $childKind, $childTight);

                    continue;
                }

                $this->deliverItemBlock($top, $this->renderBlock($source, $columns, $cursor, $childTight, self::STACK_LIMIT));

                continue;
            }

            if ($this->isContainer($columns, $cursor, $childKind)) {
                $stack[] = $this->makeFrame($source, $columns, $cursor, $childKind, $childTight);

                continue;
            }

            $top->buf .= $this->renderBlock($source, $columns, $cursor, $childTight, self::STACK_LIMIT);
        }

        return $result;
    }

    private function makeFrame(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal, string $kind, bool $incomingTight): HtmlBlockFrame
    {
        $kindId = $columns->kind[$ordinal];
        $renderer = $this->blockRenderers[$kindId] ?? null;
        $decorator = $this->blockDecorators[$kindId] ?? null;

        if (null !== $renderer) {
            $frame = new HtmlBlockFrame(HtmlBlockFrame::CUSTOM, $ordinal, false, $columns->firstChild[$ordinal]);
            $frame->customRenderer = $renderer;
            $frame->customContext = $this->blockOutputContext($source, $columns, $ordinal);
            if (null !== $decorator) {
                $frame->decorator = $decorator;
                $frame->decoratorContext = $this->htmlNodeContext($source, $columns, $ordinal, $kind);
            }

            return $frame;
        }

        switch ($kind) {
            case 'block-quote':
                $frame = new HtmlBlockFrame(HtmlBlockFrame::QUOTE, $ordinal, false, $columns->firstChild[$ordinal]);
                $frame->buf = "<blockquote>\n";
                $frame->suffix = "</blockquote>\n";

                break;

            case 'github:alert':
                $frame = new HtmlBlockFrame(HtmlBlockFrame::ALERT, $ordinal, false, $columns->firstChild[$ordinal]);
                $frame->buf = $this->alertOpen($columns, $ordinal);
                $frame->suffix = "</div>\n";

                break;

            case 'list':
                $flags = $columns->flags[$ordinal];
                $frame = new HtmlBlockFrame(HtmlBlockFrame::LIST, $ordinal, 0 === ($flags & 2), $columns->firstChild[$ordinal]);

                if (0 !== ($flags & 1)) {
                    $start = (int) ($columns->payload[$ordinal] ?? '1');
                    $frame->buf = '<ol'.(1 === $start ? '' : ' start="'.$start.'"').">\n";
                    $frame->suffix = "</ol>\n";
                } else {
                    $frame->buf = "<ul>\n";
                    $frame->suffix = "</ul>\n";
                }

                break;

            case DescriptionListExtension::LIST_KIND:
                $frame = new HtmlBlockFrame(HtmlBlockFrame::QUOTE, $ordinal, false, $columns->firstChild[$ordinal]);
                $frame->buf = "<dl>\n";
                $frame->suffix = "</dl>\n";

                break;

            case DescriptionListExtension::DESCRIPTION_KIND:
                $frame = new HtmlBlockFrame(
                    HtmlBlockFrame::DESCRIPTION,
                    $ordinal,
                    0 !== ($columns->flags[$ordinal] & 1),
                    $columns->firstChild[$ordinal],
                );
                $frame->tag = 'dd';

                break;

            default:
                $frame = new HtmlBlockFrame(HtmlBlockFrame::ITEM, $ordinal, $incomingTight, $columns->firstChild[$ordinal]);
                $frame->task = $this->taskCheckbox($columns, $ordinal);

                break;
        }

        if (null !== $decorator) {
            $frame->decorator = $decorator;
            $frame->decoratorContext = $this->htmlNodeContext($source, $columns, $ordinal, $kind);
        }

        return $frame;
    }

    private function isContainerKind(string $kind): bool
    {
        return 'block-quote' === $kind
            || 'github:alert' === $kind
            || 'list' === $kind
            || 'list-item' === $kind
            || DescriptionListExtension::LIST_KIND === $kind
            || DescriptionListExtension::DESCRIPTION_KIND === $kind;
    }

    private function isItemFrame(HtmlBlockFrame $frame): bool
    {
        return HtmlBlockFrame::ITEM === $frame->kind || HtmlBlockFrame::DESCRIPTION === $frame->kind;
    }

    private function isContainer(ParseTapeColumns $columns, int $ordinal, string $kind): bool
    {
        return isset($this->blockRenderers[$columns->kind[$ordinal]])
            || $this->isContainerKind($kind);
    }

    private function configureBlockHandlers(HtmlRenderSource $source): void
    {
        $profile = $source->compiledProfile();
        $this->blockRenderers = $profile->htmlBlockRenderers;
        $this->blockDecorators = $profile->htmlBlockDecorators;
    }

    private function renderCustomBlock(
        HtmlBlockRenderer $renderer,
        HtmlBlockOutputContext $context,
        string $children,
    ): string {
        return $renderer instanceof PlannedHtmlBlockRenderer
            ? $renderer->renderWithPlan(
                $context,
                $children,
                $this->documentRenderPlan ?? throw new \LogicException('Missing document render plan.'),
            )
            : $renderer->render($context, $children);
    }

    private function blockOutputContext(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal): HtmlBlockOutputContext
    {
        return new HtmlBlockOutputContext(
            $source->htmlTape()->extensionBlockState($ordinal),
            new \Alto\Markdown\Source\SourceRange($columns->startOffset[$ordinal], $columns->endOffset[$ordinal]),
            $source->htmlSourceBytes(),
            $this->policy,
            fn (string $markdown, ParseOptions $options): string => $this->renderMarkdownFragment(
                $source,
                $markdown,
                $options,
                $columns->kind[$ordinal],
            ),
        );
    }

    private function renderMarkdownFragment(
        HtmlRenderSource $parent,
        string $markdown,
        ParseOptions $options,
        int $excludedBlockKind,
    ): string {
        $profile = $parent->compiledProfile();
        $inline = new InlineParser($profile);
        $source = new SyntaxHtmlRenderSource(
            new SyntaxParser($profile)->parseFragmentWithoutBlockKind(
                $markdown,
                $options,
                $excludedBlockKind,
            ),
            $inline,
        );
        $renderer = new self(
            [] === $profile->htmlInlineDecorators
                ? new FusedInlineRenderer($profile)
                : new HtmlInlineRenderer(),
        );

        return $renderer->renderDocumentFragment($source, $this->policy);
    }

    /**
     * @param array<string, bool|int|string|null>|null $materializedAttributes
     */
    private function htmlNodeContext(
        HtmlRenderSource $source,
        ParseTapeColumns $columns,
        int $ordinal,
        string $kind,
        ?array $materializedAttributes = null,
    ): HtmlNodeOutputContext {
        $start = $columns->startOffset[$ordinal];
        $end = $columns->endOffset[$ordinal];
        $flags = $columns->flags[$ordinal];
        $payload = $columns->payload[$ordinal] ?? null;
        $attributes = $materializedAttributes ?? match ($kind) {
            'atx-heading', 'setext-heading' => ['level' => $flags],
            'list' => [
                'ordered' => 0 !== ($flags & 1),
                'tight' => 0 === ($flags & 2),
                'start' => (int) ($payload ?? '1'),
            ],
            'list-item' => [
                'task' => null === $payload ? null : match ($payload) {
                    'task:checked' => 'checked',
                    'task:unchecked' => 'unchecked',
                    default => null,
                },
            ],
            'github:alert' => ['type' => strtolower($payload ?? 'note')],
            default => [],
        };

        return new HtmlNodeOutputContext(
            $kind,
            new \Alto\Markdown\Source\SourceRange($start, $end),
            substr($source->htmlSourceBytes(), $start, $end - $start),
            $this->policy,
            $attributes,
            $ordinal,
        );
    }

    private function deliverItemBlock(HtmlBlockFrame $item, string $rendered): void
    {
        $rendered = rtrim($rendered, "\n");

        if ('' !== $rendered) {
            $item->parts[] = $rendered;
            $item->lastIsInline = false;
        }
    }

    private function finishItem(HtmlBlockFrame $item): string
    {
        if ([] === $item->parts) {
            return '<'.$item->tag.'></'.$item->tag.">\n";
        }

        $prefix = $item->firstIsInline ? '' : "\n";
        $suffix = $item->lastIsInline ? '' : "\n";

        return '<'.$item->tag.'>'.$prefix.$item->task.implode("\n", $item->parts).$suffix.'</'.$item->tag.">\n";
    }

    private function alertOpen(ParseTapeColumns $columns, int $ordinal): string
    {
        $type = strtolower($columns->payload[$ordinal] ?? 'note');

        if (!\in_array($type, ['note', 'tip', 'important', 'warning', 'caution'], true)) {
            $type = 'note';
        }

        return '<div class="markdown-alert markdown-alert-'.$type.'">'."\n"
            .'<p class="markdown-alert-title">'.ucfirst($type)."</p>\n";
    }

    private function heading(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal, string $kind, bool $hardBreaks): string
    {
        $level = $columns->flags[$ordinal];
        $decorators = $source->compiledProfile()->htmlHeadingDecorators;
        $content = null === $decorators
            ? $this->inline->renderBlockInlines($source, $ordinal, $hardBreaks, $this->policy)
            : $this->inline->renderHeadingInlines($source, $ordinal, $hardBreaks, $this->policy);
        $html = \sprintf("<h%d>%s</h%d>\n", $level, $content, $level);

        if (null === $decorators) {
            return $html;
        }

        $start = $columns->startOffset[$ordinal];
        $end = $columns->endOffset[$ordinal];

        return $decorators->decorate(new HtmlNodeOutputContext(
            $kind,
            new \Alto\Markdown\Source\SourceRange($start, $end),
            substr($source->htmlSourceBytes(), $start, $end - $start),
            $this->policy,
            [
                'level' => $level,
                'slug' => $source->headingSlug($ordinal),
            ],
        ), $html);
    }

    private function projectDocumentTransforms(
        HtmlRenderSource $source,
        ParseTapeColumns $columns,
        bool $completeDocument,
    ): ParseTapeColumns {
        $transforms = $source->compiledProfile()->documentTransforms;

        if (null === $transforms) {
            return $columns;
        }

        $this->documentRenderPlan = $transforms->plan($source, $completeDocument);

        return $this->documentRenderPlan->project($columns);
    }

    /**
     * @return array{language: string|null, code: string}|array{language: string|null, info: string, code: string}
     */
    private function codeBlockAttributes(HtmlRenderSource $source, int $ordinal, string $kind): array
    {
        if (Instrumentation::$timing) {
            Instrumentation::enter('opaque-read');
        }

        [$language, $info, $code] = $source->codeBlockParts($ordinal);

        if (Instrumentation::$timing) {
            Instrumentation::leave('opaque-read');
        }

        return 'fenced-code' === $kind
            ? ['language' => $language, 'info' => $info, 'code' => $code]
            : ['language' => $language, 'code' => $code];
    }

    /**
     * @param array{language: string|null, code: string}|array{language: string|null, info: string, code: string} $attributes
     */
    private function codeBlock(array $attributes): string
    {
        $language = $attributes['language'];
        $code = $attributes['code'];
        $attribute = null === $language ? '' : ' class="language-'.HtmlEscaper::attribute($language).'"';

        return '<pre><code'.$attribute.'>'.HtmlEscaper::text($code)."</code></pre>\n";
    }

    private function taskCheckbox(ParseTapeColumns $columns, int $ordinal): string
    {
        return match ($columns->payload[$ordinal] ?? null) {
            'task:unchecked' => '<input disabled="" type="checkbox"> ',
            'task:checked' => '<input checked="" disabled="" type="checkbox"> ',
            default => '',
        };
    }

    private function table(HtmlRenderSource $source, int $ordinal): string
    {
        if (Instrumentation::$timing) {
            Instrumentation::enter('opaque-read');
        }

        [$header, $alignments, $body] = $source->tableParts($ordinal);

        if (Instrumentation::$timing) {
            Instrumentation::leave('opaque-read');
        }

        $html = "<table>\n<thead>\n<tr>\n";

        foreach ($header as $index => $cell) {
            $html .= '<th'.$this->alignAttribute($alignments[$index] ?? '').'>'.$this->cellHtml($source, $cell)."</th>\n";
        }

        $html .= "</tr>\n</thead>\n";

        if ([] !== $body) {
            $html .= "<tbody>\n";

            foreach ($body as $row) {
                $html .= "<tr>\n";

                foreach ($header as $index => $_cell) {
                    $html .= '<td'.$this->alignAttribute($alignments[$index] ?? '').'>'.$this->cellHtml($source, $row[$index] ?? '')."</td>\n";
                }

                $html .= "</tr>\n";
            }

            $html .= "</tbody>\n";
        }

        return $html."</table>\n";
    }

    private function cellHtml(HtmlRenderSource $source, string $cell): string
    {
        if (Instrumentation::$timing) {
            Instrumentation::enter('plain-scan');
        }

        $plain = null === $source->inlineCountBudget()
            ? PlainInlineText::render($cell, $source->compiledProfile())
            : null;

        if (Instrumentation::$timing) {
            Instrumentation::leave('plain-scan');
        }

        return $plain ?? $this->inline->renderMarkdown($source, $cell, $this->policy);
    }

    private function paragraphContent(HtmlRenderSource $source, int $ordinal): string
    {
        return $this->inline->renderBlockInlines($source, $ordinal, true, $this->policy);
    }

    /**
     * A raw HTML block whose parent is the tape root is an opaque leaf: its
     * member lines are contiguous in the input, so the whole span is read
     * here in one slice and the walk never leaves the column arrays (RP.8).
     * Anywhere else the block sits under a container that prefixes every
     * continuation line, and only the source can rejoin the per-line ranges
     * the parser recorded past those prefixes.
     */
    private function rawBlockHtml(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal): string
    {
        if (Instrumentation::$timing) {
            Instrumentation::enter('opaque-read');
        }

        if ($columns->parent[$ordinal] === $this->rootOrdinal) {
            $start = $columns->startOffset[$ordinal];
            $html = substr($source->htmlSourceBytes(), $start, $columns->endOffset[$ordinal] - $start);
        } else {
            $html = $source->htmlBlockSource($ordinal);
        }

        $result = match ($this->policy->rawHtml) {
            RawHtmlPolicy::Strip => '',
            RawHtmlPolicy::Escape => HtmlEscaper::text($html),
            RawHtmlPolicy::Allow => $this->rawBlockPassthrough($source, $html),
        };

        if (Instrumentation::$timing) {
            Instrumentation::leave('opaque-read');
        }

        return $result;
    }

    /**
     * Spec-mode raw block emission: verbatim, with the GFM tagfilter
     * applied except when the block itself opens with a filtered tag.
     */
    private function rawBlockPassthrough(HtmlRenderSource $source, string $html): string
    {
        if ($this->policy->sanitizesHtml()) {
            return $html;
        }

        if (!$source->compiledProfile()->tagFilter || 1 === preg_match('/^<\/?(?:title|textarea|style|xmp|iframe|noembed|noframes|script|plaintext)(?:\s|>|\/)/i', $html)) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<(?=\/?(?:title|textarea|style|xmp|iframe|noembed|noframes|script|plaintext)(?:\s|>|\/))/i',
            static fn (): string => '&lt;',
            $html,
        );
    }

    private function finalizeHtml(string $html): string
    {
        if (!$this->policy->sanitizesHtml()) {
            return $html;
        }

        $timing = Instrumentation::$timing;
        $depth = Instrumentation::regionDepth();

        if ($timing) {
            Instrumentation::enter('sanitize-html');
        }

        try {
            return $this->policy->sanitizeHtml($html);
        } finally {
            if ($timing) {
                Instrumentation::unwindRegions($depth);
            }
        }
    }

    private function kindName(HtmlRenderSource $source, ParseTapeColumns $columns, int $ordinal): string
    {
        return $source->compiledProfile()->nodeKinds->get($columns->kind[$ordinal])->name;
    }

    private function alignAttribute(string $alignment): string
    {
        return '' === $alignment ? '' : ' align="'.$alignment.'"';
    }
}
