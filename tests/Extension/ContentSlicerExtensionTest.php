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
use Alto\Markdown\Extension\ContentSlicer\ContentSlicerExtension;
use Alto\Markdown\Extension\ContentSlicer\ContentSlicerLayout;
use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Footnote\FootnoteExtension;
use Alto\Markdown\Extension\HeadingLevel\HeadingLevelExtension;
use Alto\Markdown\Extension\HeadingLevel\HeadingLevelPolicy;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentSlicerExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testBuildsNestedRootSectionsWithoutChangingMarkdown(): void
    {
        $factory = Markdown::commonmark()->with(new ContentSlicerExtension());
        $source = "# Main\n\nContent 1.\n\n"
            . "## Sub 1\n\nContent 2.\n\n"
            . "#### Sub 1.1\n\nContent 3.\n\n"
            . "## Sub 2\n\nContent 4.\n";
        $expected = "<h1>Main</h1>\n"
            . "<p>Content 1.</p>\n"
            . "<section>\n"
            . "<h2>Sub 1</h2>\n"
            . "<p>Content 2.</p>\n"
            . "<section>\n"
            . "<h4>Sub 1.1</h4>\n"
            . "<p>Content 3.</p>\n"
            . "</section>\n"
            . "</section>\n"
            . "<section>\n"
            . "<h2>Sub 2</h2>\n"
            . "<p>Content 4.</p>\n"
            . "</section>\n";

        self::assertSame($expected, $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
        self::assertTrue($document->diff()->isEmpty());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function minimumLevels(): iterable
    {
        yield 'all headings' => [
            1,
            "<section>\n<h1>One</h1>\n"
            . "<section>\n<h2>Two</h2>\n"
            . "<section>\n<h3>Three</h3>\n"
            . "<section>\n<h6>Six</h6>\n</section>\n"
            . "</section>\n"
            . "</section>\n</section>\n",
        ];
        yield 'h3 and deeper' => [
            3,
            "<h1>One</h1>\n<h2>Two</h2>\n"
            . "<section>\n<h3>Three</h3>\n"
            . "<section>\n<h6>Six</h6>\n</section>\n"
            . "</section>\n",
        ];
        yield 'h6 only' => [
            6,
            "<h1>One</h1>\n<h2>Two</h2>\n<h3>Three</h3>\n"
            . "<section>\n<h6>Six</h6>\n</section>\n",
        ];
    }

    #[DataProvider('minimumLevels')]
    public function testMinimumLevelSelectsWhichHeadingsOpenSections(int $minLevel, string $expected): void
    {
        $source = "# One\n\n## Two\n\n### Three\n\n###### Six\n";

        self::assertSame(
            $expected,
            Markdown::commonmark()
                ->with(new ContentSlicerExtension($minLevel))
                ->toHtml($source),
        );
    }

    public function testKeepsPreambleAndNestedHeadingsOutsideTheRootOutline(): void
    {
        $source = "Preamble.\n\n> ## Quoted\n>\n> Body.\n\n"
            . "- item\n  - nested\n\n"
            . "## Root\n\nBody.\n";
        $expected = "<p>Preamble.</p>\n"
            . "<blockquote>\n<h2>Quoted</h2>\n<p>Body.</p>\n</blockquote>\n"
            . "<ul>\n<li>item\n<ul>\n<li>nested</li>\n</ul>\n</li>\n</ul>\n"
            . "<section>\n<h2>Root</h2>\n<p>Body.</p>\n</section>\n";

        self::assertSame(
            $expected,
            Markdown::commonmark()
                ->with(new ContentSlicerExtension())
                ->toHtml($source),
        );
    }

    public function testNoHeadingAndNoMatchingHeadingKeepTheNormalRenderPath(): void
    {
        $noHeading = "First.\n\nSecond.\n";
        $onlyH1 = "# Title\n\nBody.\n";
        $factory = Markdown::commonmark()->with(new ContentSlicerExtension());

        Instrumentation::reset();
        self::assertSame(Markdown::commonmark()->toHtml($noHeading), $factory->toHtml($noHeading));
        self::assertSame(1, Instrumentation::$documentTransformHeadingViews);
        self::assertSame(0, Instrumentation::$documentTransformBlockViews);

        Instrumentation::reset();
        self::assertSame(Markdown::commonmark()->toHtml($onlyH1), $factory->toHtml($onlyH1));
        self::assertSame(1, Instrumentation::$documentTransformHeadingViews);
        self::assertSame(0, Instrumentation::$documentTransformBlockViews);
    }

    public function testUsesEffectiveHeadingLevelsIndependentOfRegistrationOrder(): void
    {
        $levels = new HeadingLevelExtension(HeadingLevelPolicy::shift(1));
        $slices = new ContentSlicerExtension(minLevel: 3);
        $source = "# One\n\n## Two\n";
        $expected = "<h2>One</h2>\n<section>\n<h3>Two</h3>\n</section>\n";

        self::assertSame(
            $expected,
            Markdown::commonmark()->with($levels, $slices)->toHtml($source),
        );
        self::assertSame(
            $expected,
            Markdown::commonmark()->with($slices, $levels)->toHtml($source),
        );
    }

    public function testPartialRenderingOmitsDocumentLevelSectionShells(): void
    {
        $source = "# One\n\nLead.\n\n## Two\n\nBody.\n\n### Three\n\nDetails.\n\n## Four\n";
        $document = Markdown::commonmark()
            ->with(new ContentSlicerExtension())
            ->fromString($source);
        $renderer = new HtmlDocumentRenderer();
        $headings = $document->headings()->all();

        self::assertCount(4, $headings);
        self::assertSame(
            "<h2>Two</h2>\n",
            $renderer->renderNode($document->model(), $headings[1]),
        );
        self::assertSame(0, Instrumentation::$documentTransformHeadingViews);

        Instrumentation::reset();
        self::assertSame(
            "<h2>Two</h2>\n<p>Body.</p>\n<h3>Three</h3>\n<p>Details.</p>\n",
            $renderer->renderSection($document->model(), $document->section('Two')),
        );
        self::assertSame(0, Instrumentation::$documentTransformHeadingViews);
    }

    public function testCuratedPolicyPreservesContentWhileUnwrappingSections(): void
    {
        $html = Markdown::commonmark()
            ->with(new ContentSlicerExtension())
            ->toHtml(
                "## Safe\n\nBody.\n",
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
            );

        self::assertStringContainsString("<h2>Safe</h2>\n", $html);
        self::assertStringContainsString("<p>Body.</p>\n", $html);
        self::assertStringNotContainsString('<section>', $html);
    }

    public function testGeneratedFootnotesStayOutsideTheLastSourceSection(): void
    {
        $slicer = new ContentSlicerExtension();
        $footnotes = new FootnoteExtension();
        $source = "## Notes\n\nBody[^one].\n\n[^one]: Detail.\n";

        foreach ([[$slicer, $footnotes], [$footnotes, $slicer]] as $extensions) {
            $html = Markdown::commonmark()->with(...$extensions)->toHtml($source);

            self::assertStringContainsString(
                "</p>\n</section>\n<div class=\"footnotes\" role=\"doc-endnotes\">\n",
                $html,
            );
        }
    }

    public function testEmptyAndConflictingRootLayoutsAreHandledExplicitly(): void
    {
        $plan = new DocumentRenderPlan();
        $plan->provide(new ContentSlicerLayout([], 0));

        self::assertFalse($plan->hasRootHtmlLayout());
        self::assertSame('', $plan->htmlBeforeRootBlock(1));
        self::assertSame('', $plan->htmlAfterRootBlocks());

        $plan->provide(new ContentSlicerLayout([1 => "<section>\n"], 1));

        self::assertTrue($plan->hasRootHtmlLayout());
        self::assertSame("<section>\n", $plan->htmlBeforeRootBlock(1));
        self::assertSame("</section>\n", $plan->htmlAfterRootBlocks());

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Only one document root HTML layout');

        $plan->provide(new ContentSlicerLayout([2 => "<section>\n"], 1));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidMinimumLevels(): iterable
    {
        yield 'zero' => [0];
        yield 'above six' => [7];
    }

    #[DataProvider('invalidMinimumLevels')]
    public function testRejectsInvalidMinimumLevels(int $minLevel): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('between 1 and 6');

        new ContentSlicerExtension($minLevel);
    }
}
