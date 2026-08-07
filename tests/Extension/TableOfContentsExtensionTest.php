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

namespace Alto\Markdown\Tests\Extension;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\BlockExtensionInterface;
use Alto\Markdown\Extension\Document\DocumentTransform;
use Alto\Markdown\Extension\Document\DocumentTransformContext;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPolicy;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPosition;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsExtension;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsHeadingDecorator;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsOutput;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsParser;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsPolicy;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsStyle;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableOfContentsExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testRendersRichRootHeadingsInBothLanesWithoutChangingMarkdown(): void
    {
        $factory = Markdown::commonmark()->with(new TableOfContentsExtension());
        $source = "# Guide\n\n@toc {min: 2}\n\n## Intro *now*\n\n### Use [`it`](https://example.com)\n";
        $expected = "<h1 id=\"guide\">Guide</h1>\n"
            ."<nav class=\"table-of-contents\" id=\"toc\">\n"
            ."<ul>\n"
            ."<li><a href=\"#intro-now\">Intro now</a>\n"
            ."<ul>\n"
            ."<li><a href=\"#use-it\">Use it</a></li>\n"
            ."</ul>\n"
            ."</li>\n"
            ."</ul>\n"
            ."</nav>\n"
            ."<h2 id=\"intro-now\">Intro <em>now</em></h2>\n"
            ."<h3 id=\"use-it\">Use <a href=\"https://example.com\"><code>it</code></a></h3>\n";

        self::assertSame($expected, $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame($source, $document->toMarkdown(new RenderOptions()));
        self::assertFalse($document->hasChanges());
        self::assertTrue($document->diff()->isEmpty());
    }

    public function testAssignsStableUniqueHeadingIdsEvenWithoutAMarker(): void
    {
        $factory = Markdown::commonmark()->with(new TableOfContentsExtension());
        $source = "# Repeat\n\n# Repeat\n\n# Repeat-1\n\n# !\n\n# toc-heading\n";
        $expected = "<h1 id=\"repeat\">Repeat</h1>\n"
            ."<h1 id=\"repeat-1\">Repeat</h1>\n"
            ."<h1 id=\"repeat-1-1\">Repeat-1</h1>\n"
            ."<h1 id=\"toc-heading\">!</h1>\n"
            ."<h1 id=\"toc-heading-toc-heading\">toc-heading</h1>\n";

        Instrumentation::reset();
        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame(1, Instrumentation::$documentTransformBlockViews);
        self::assertSame(0, Instrumentation::$documentTransformHeadingViews);

        self::assertSame($expected, $factory->fromString($source)->toHtml());
    }

    public function testMultipleMarkersShareOneCatalogAndApplyLocalOptions(): void
    {
        $factory = Markdown::commonmark()->with(new TableOfContentsExtension());
        $source = "# Top\n\n@toc {min: 2, max: 2}\n\n## Middle\n\n@toc {min: 3, ordered: true}\n\n### Deep\n";

        Instrumentation::reset();
        $html = $factory->toHtml($source);

        self::assertStringContainsString(
            "<nav class=\"table-of-contents\" id=\"toc\">\n"
            ."<ul>\n"
            ."<li><a href=\"#middle\">Middle</a></li>\n"
            ."</ul>\n"
            ."</nav>\n",
            $html,
        );
        self::assertStringContainsString(
            "<nav class=\"table-of-contents\" id=\"toc-1\">\n"
            ."<ol>\n"
            ."<li><a href=\"#deep\">Deep</a></li>\n"
            ."</ol>\n"
            ."</nav>\n",
            $html,
        );
        self::assertSame(1, Instrumentation::$documentTransformInvocations);
        self::assertSame(1, Instrumentation::$documentTransformHeadingViews);
    }

    public function testNestedHeadingsAreAnchoredButExcludedFromTheCatalog(): void
    {
        $factory = Markdown::commonmark()->with(new TableOfContentsExtension());

        self::assertSame(
            "<h1 id=\"top\">Top</h1>\n"
            ."<nav class=\"table-of-contents\" id=\"toc\">\n"
            ."<ul>\n"
            ."<li><a href=\"#top\">Top</a>\n"
            ."<ul>\n"
            ."<li><a href=\"#root\">Root</a></li>\n"
            ."</ul>\n"
            ."</li>\n"
            ."</ul>\n"
            ."</nav>\n"
            ."<blockquote>\n"
            ."<h2 id=\"nested\">Nested</h2>\n"
            ."</blockquote>\n"
            ."<h2 id=\"root\">Root</h2>\n",
            $factory->toHtml("# Top\n\n@toc\n\n> ## Nested\n\n## Root\n"),
        );
    }

    public function testCustomPolicyControlsMarkerWrapperTitleAndDefaults(): void
    {
        $factory = Markdown::commonmark()->with(new TableOfContentsExtension(
            new TableOfContentsPolicy(
                minLevel: 2,
                maxLevel: 4,
                style: TableOfContentsStyle::Ordered,
                htmlClass: 'contents "wide"',
                id: 'guide',
                title: 'On <this> page',
                marker: '[[toc]]',
            ),
        ));

        self::assertSame(
            "<h1 id=\"top\">Top</h1>\n"
            ."<nav class=\"contents &quot;wide&quot;\" id=\"guide\">\n"
            ."<p class=\"table-of-contents-title\">On &lt;this&gt; page</p>\n"
            ."<ol>\n"
            ."<li><a href=\"#details\">Details</a></li>\n"
            ."</ol>\n"
            ."</nav>\n"
            ."<h2 id=\"details\">Details</h2>\n",
            $factory->toHtml("# Top\n\n[[toc]]\n\n## Details\n"),
        );

        $plain = Markdown::commonmark()->with(new TableOfContentsExtension(
            new TableOfContentsPolicy(htmlClass: '', id: ''),
        ));
        self::assertStringContainsString("<nav>\n<ul>", $plain->toHtml("@toc\n\n# One\n"));
    }

    public function testNoMatchingHeadingRemovesOnlyTheRenderedMarker(): void
    {
        $factory = Markdown::commonmark()->with(new TableOfContentsExtension());
        $source = "@toc {min: 3}\n\n## Too shallow\n";
        $document = $factory->fromString($source);

        self::assertSame("<h2 id=\"too-shallow\">Too shallow</h2>\n", $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
    }

    public function testCuratedPolicyKeepsListContentButRemovesNavigationTargets(): void
    {
        if (!class_exists(\Dom\HTMLDocument::class)) {
            self::markTestSkipped('Curated rendering requires the PHP DOM extension.');
        }

        $html = Markdown::commonmark()
            ->with(new TableOfContentsExtension())
            ->toHtml(
                "@toc\n\n# Title\n\n## Child\n",
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
            );

        self::assertSame(
            "\n<ul>\n"
            ."<li><a href=\"#title\">Title</a>\n"
            ."<ul>\n"
            ."<li><a href=\"#child\">Child</a></li>\n"
            ."</ul>\n"
            ."</li>\n"
            ."</ul>\n"
            ."\n<h1>Title</h1>\n"
            ."<h2>Child</h2>\n",
            $html,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDirectives(): iterable
    {
        yield 'one leading space' => [' @toc'];
        yield 'three leading spaces' => ['   @toc'];
        yield 'four leading spaces' => ['    @toc'];
        yield 'extra text' => ['@toc now'];
        yield 'same trigger byte' => ['@other'];
        yield 'missing separator' => ['@toc{min: 2}'];
        yield 'unknown option' => ['@toc {depth: 2}'];
        yield 'duplicate option' => ['@toc {min: 2, min: 3}'];
        yield 'quoted level' => ['@toc {min: "2"}'];
        yield 'zero level' => ['@toc {min: 0}'];
        yield 'large level' => ['@toc {max: 7}'];
        yield 'reversed range' => ['@toc {min: 4, max: 2}'];
        yield 'invalid boolean' => ['@toc {ordered: yes}'];
        yield 'quoted boolean' => ['@toc {ordered: "true"}'];
        yield 'missing brace' => ['@toc {min: 2'];
        yield 'nested brace' => ['@toc {{min: 2}}'];
        yield 'trailing comma' => ['@toc {min: 2,}'];
        yield 'over byte limit' => ['@toc '.str_repeat('x', 508)];
    }

    #[DataProvider('invalidDirectives')]
    public function testInvalidOrIndentedDirectivesRemainLiteralMarkdown(string $directive): void
    {
        $source = $directive."\n";
        $document = Markdown::commonmark()
            ->with(new TableOfContentsExtension())
            ->fromString($source);

        self::assertNotSame('', $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
    }

    public function testParagraphContinuationCannotBecomeAMarker(): void
    {
        $factory = Markdown::commonmark()->with(new TableOfContentsExtension());

        self::assertSame(
            "<p>Before\n@toc</p>\n<h1 id=\"after\">After</h1>\n",
            $factory->toHtml("Before\n@toc\n\n# After\n"),
        );
    }

    public function testAcceptsCrLfAndTrailingSpacesWithoutChangingSource(): void
    {
        $factory = Markdown::commonmark()->with(new TableOfContentsExtension());
        $source = "@toc   \r\n\r\n# Title\r\n";
        $document = $factory->fromString($source);

        self::assertStringContainsString('<a href="#title">Title</a>', $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
    }

    public function testCustomMarkerMustMatchTheWholeDirectivePrefix(): void
    {
        $factory = Markdown::commonmark()->with(new TableOfContentsExtension(
            new TableOfContentsPolicy(marker: '[[toc]]'),
        ));
        $source = "[[toc]]-extended\n";

        self::assertStringContainsString('[[toc]]-extended', $factory->toHtml($source));
        self::assertSame($source, $factory->fromString($source)->toMarkdown());
    }

    public function testNodeAndSectionRenderingUseTheWholeDocumentCatalog(): void
    {
        $document = Markdown::commonmark()
            ->with(new TableOfContentsExtension())
            ->fromString("# Repeat\n\n@toc\n\n## Child\n\n# Repeat\n");
        $marker = $document->query()->kind('table-of-contents:block')->get()->first();
        $second = $document->headings(1)->all()[1] ?? null;
        $section = $document->section('Repeat');
        $renderer = new HtmlDocumentRenderer();

        self::assertNotNull($marker);
        self::assertNotNull($second);
        self::assertStringContainsString('<li><a href="#repeat-1">Repeat</a></li>', $renderer->renderNode($document->model(), $marker));
        self::assertSame("<h1 id=\"repeat-1\">Repeat</h1>\n", $renderer->renderNode($document->model(), $second));
        self::assertStringContainsString('<li><a href="#child">Child</a></li>', $renderer->renderSection($document->model(), $section));
    }

    public function testCatalogAndSlugsRebuildAfterDocumentMutation(): void
    {
        $document = Markdown::commonmark()
            ->with(new TableOfContentsExtension())
            ->fromString("@toc\n\n# Old\n");

        self::assertStringContainsString('<a href="#old">Old</a>', $document->toHtml());

        $heading = $document->headings()->first();
        self::assertNotNull($heading);
        $heading->rename('New');

        self::assertStringContainsString('<a href="#new">New</a>', $document->toHtml());
        self::assertStringNotContainsString('href="#old"', $document->toHtml());
    }

    public function testCatalogUsesHeadingLevelProjectedByAnEarlierTransform(): void
    {
        $factory = Markdown::commonmark()->with(
            new TocLevelExtension(),
            new TableOfContentsExtension(),
        );

        self::assertSame(
            "<nav class=\"table-of-contents\" id=\"toc\">\n"
            ."<ul>\n"
            ."<li><a href=\"#projected\">Projected</a></li>\n"
            ."</ul>\n"
            ."</nav>\n"
            ."<h3 id=\"projected\">Projected</h3>\n",
            $factory->toHtml("@toc {min: 3, max: 3}\n\n# Projected\n"),
        );
    }

    public function testComposesAfterHeadingPermalinksWithoutDuplicateHeadingIds(): void
    {
        $factory = Markdown::commonmark()->with(
            new HeadingPermalinkExtension(new HeadingPermalinkPolicy(
                position: HeadingPermalinkPosition::None,
                idPrefix: 'content',
                applyIdToHeading: true,
            )),
            new TableOfContentsExtension(),
        );

        $html = $factory->toHtml("@toc\n\n# Title\n");

        self::assertStringContainsString('<a href="#title">Title</a>', $html);
        self::assertStringContainsString('<span id="title"></span><h1 id="content-title">Title</h1>', $html);
        self::assertSame(1, substr_count($html, '<h1 id='));

        $sameId = Markdown::commonmark()->with(
            new HeadingPermalinkExtension(new HeadingPermalinkPolicy(
                position: HeadingPermalinkPosition::None,
                idPrefix: '',
                applyIdToHeading: true,
            )),
            new TableOfContentsExtension(),
        );
        self::assertStringNotContainsString('<span', $sameId->toHtml("# Title\n"));
    }

    public function testPlannedBlockRendererRequiresATransformAndProjection(): void
    {
        try {
            Markdown::commonmark()->with(new BrokenTocExtension(false));
            self::fail('Expected the missing document transform to fail profile compilation.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertStringContainsString('planned HTML block renderer requires a document transform', $error->getMessage());
        }

        $factory = Markdown::commonmark()->with(new BrokenTocExtension(true));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Missing table of contents projection.');

        $factory->toHtml("@toc\n\n# Title\n");
    }

    public function testPlannedOutputAndHeadingDecoratorRejectMissingIntermediateState(): void
    {
        $policy = new TableOfContentsPolicy();
        $output = new TableOfContentsOutput($policy);
        $context = new HtmlBlockOutputContext(
            new BlockState(),
            new SourceRange(0, 0),
            '',
            HtmlPolicy::safe(),
        );

        try {
            $output->render($context, '');
            self::fail('Expected the missing document plan to fail.');
        } catch (\LogicException $error) {
            self::assertSame('Table of contents output requires a document render plan.', $error->getMessage());
        }

        $decorator = new TableOfContentsHeadingDecorator();
        $heading = new HtmlNodeOutputContext(
            'atx-heading',
            null,
            '# Title',
            HtmlPolicy::safe(),
            ['level' => 1, 'slug' => 'title'],
        );

        self::assertSame('plain', $decorator->decorate($heading, 'plain'));
        self::assertSame('<h1', $decorator->decorate($heading, '<h1'));
    }

    /**
     * @return iterable<string, array{\Closure(): TableOfContentsPolicy}>
     */
    public static function invalidPolicies(): iterable
    {
        yield 'minimum below one' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(minLevel: 0)];
        yield 'minimum above six' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(minLevel: 7)];
        yield 'maximum below one' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(maxLevel: 0)];
        yield 'maximum above six' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(maxLevel: 7)];
        yield 'reversed range' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(minLevel: 4, maxLevel: 2)];
        yield 'class control byte' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(htmlClass: "toc\nwide")];
        yield 'class too long' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(htmlClass: str_repeat('x', 257))];
        yield 'id whitespace' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(id: 'table contents')];
        yield 'id too long' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(id: str_repeat('x', 129))];
        yield 'title control byte' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(title: "bad\x01title")];
        yield 'title too long' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(title: str_repeat('x', 513))];
        yield 'empty marker' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(marker: '')];
        yield 'marker whitespace' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(marker: 'table contents')];
        yield 'marker brace' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(marker: '[{toc}]')];
        yield 'marker non ASCII' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(marker: 'sommaire-é')];
        yield 'marker too long' => [static fn (): TableOfContentsPolicy => new TableOfContentsPolicy(marker: str_repeat('x', 65))];
    }

    #[DataProvider('invalidPolicies')]
    public function testRejectsInvalidPolicies(\Closure $factory): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        $factory();
    }
}

final readonly class TocLevelExtension implements DocumentTransformExtensionInterface
{
    public function name(): string
    {
        return 'toc-level';
    }

    public function documentTransforms(): iterable
    {
        yield new DocumentTransformDefinition(
            'level',
            static fn (): TocLevelTransform => new TocLevelTransform(),
            \PHP_INT_MAX,
        );
    }
}

final readonly class TocLevelTransform implements DocumentTransform
{
    public function transform(DocumentTransformContext $context): void
    {
        foreach ($context->headings() as $heading) {
            $context->overrideHeadingLevel($heading, 3);
        }
    }
}

final readonly class BrokenTocExtension implements BlockExtensionInterface, DocumentTransformExtensionInterface
{
    private TableOfContentsPolicy $policy;

    public function __construct(private bool $withTransform)
    {
        $this->policy = new TableOfContentsPolicy();
    }

    public function name(): string
    {
        return 'broken-toc';
    }

    public function blocks(): iterable
    {
        $output = new TableOfContentsOutput($this->policy);

        yield new BlockDefinition(
            'block',
            new TableOfContentsParser($this->policy),
            $output,
            $output,
        );
    }

    public function documentTransforms(): iterable
    {
        if ($this->withTransform) {
            yield new DocumentTransformDefinition(
                'unrelated',
                static fn (): EmptyTocTransform => new EmptyTocTransform(),
            );
        }
    }
}

final readonly class EmptyTocTransform implements DocumentTransform
{
    public function transform(DocumentTransformContext $context): void
    {
        unset($context);
    }
}
