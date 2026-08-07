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
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPolicy;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPosition;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadingPermalinkExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testDefaultPermalinksCoverAtxSetextAndDuplicateSlugsInBothLanes(): void
    {
        $factory = Markdown::commonmark()->with(new HeadingPermalinkExtension());
        $source = "# Hello, **world!**\n\nRepeat\n======\n\n# Hello, **world!**\n";
        $expected = '<h1><a id="content-hello-world" href="#content-hello-world" '
            .'class="heading-permalink" aria-hidden="true" title="Permalink">¶</a>'
            ."Hello, <strong>world!</strong></h1>\n"
            .'<h1><a id="content-repeat" href="#content-repeat" '
            .'class="heading-permalink" aria-hidden="true" title="Permalink">¶</a>'
            ."Repeat</h1>\n"
            .'<h1><a id="content-hello-world-1" href="#content-hello-world-1" '
            .'class="heading-permalink" aria-hidden="true" title="Permalink">¶</a>'
            ."Hello, <strong>world!</strong></h1>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());
        self::assertSame($source, $factory->fromString($source)->toMarkdown());
    }

    public function testNodeAndSectionRenderingUseTheDocumentWideDuplicateSlug(): void
    {
        $document = Markdown::commonmark()
            ->with(new HeadingPermalinkExtension())
            ->fromString("# Repeat\n\nFirst.\n\n# Repeat\n\nSecond.\n");
        $headings = [...$document->headings()];
        $sections = [...$document->sections('Repeat')];
        $renderer = new HtmlDocumentRenderer();

        self::assertCount(2, $headings);
        self::assertCount(2, $sections);
        self::assertSame(
            '<h1><a id="content-repeat-1" href="#content-repeat-1" '
            .'class="heading-permalink" aria-hidden="true" title="Permalink">¶</a>'
            ."Repeat</h1>\n",
            $renderer->renderNode($document->model(), $headings[1]),
        );
        self::assertStringStartsWith(
            '<h1><a id="content-repeat-1" href="#content-repeat-1"',
            $renderer->renderSection($document->model(), $sections[1]),
        );
    }

    public function testConfiguresLevelsPositionHeadingIdClassesAndAccessibleText(): void
    {
        $factory = Markdown::commonmark()->with(new HeadingPermalinkExtension(
            new HeadingPermalinkPolicy(
                minLevel: 2,
                maxLevel: 3,
                position: HeadingPermalinkPosition::After,
                idPrefix: 'heading',
                applyIdToHeading: true,
                headingClass: 'anchored',
                fragmentPrefix: 'jump',
                htmlClass: 'permalink',
                title: 'Open "section"',
                symbol: '<#>',
                ariaHidden: false,
            ),
        ));

        self::assertSame(
            "<h1>Title</h1>\n"
            .'<h2 id="heading-details" class="anchored">Details'
            .'<a href="#jump-details" class="permalink" title="Open &quot;section&quot;">&lt;#&gt;</a>'
            ."</h2>\n"
            ."<h4>Deep</h4>\n",
            $factory->toHtml("# Title\n\n## Details\n\n#### Deep\n"),
        );
    }

    public function testFilteredHeadingsStillReserveTheirDocumentWideSlug(): void
    {
        $factory = Markdown::commonmark()->with(new HeadingPermalinkExtension(
            new HeadingPermalinkPolicy(minLevel: 2),
        ));

        self::assertSame(
            "<h1>Repeat</h1>\n"
            .'<h2><a id="content-repeat-1" href="#content-repeat-1" '
            .'class="heading-permalink" aria-hidden="true" title="Permalink">¶</a>'
            ."Repeat</h2>\n",
            $factory->toHtml("# Repeat\n\n## Repeat\n"),
        );
    }

    public function testNonePositionCanApplyOnlyHeadingAttributes(): void
    {
        $factory = Markdown::commonmark()->with(new HeadingPermalinkExtension(
            new HeadingPermalinkPolicy(
                position: HeadingPermalinkPosition::None,
                idPrefix: '',
                applyIdToHeading: true,
                headingClass: 'section',
            ),
        ));

        self::assertSame(
            "<h2 id=\"details\" class=\"section\">Details</h2>\n",
            $factory->toHtml("## Details\n"),
        );
    }

    public function testEmptyPrefixesAndClassesDoNotCreateStrayHyphensOrAttributes(): void
    {
        $factory = Markdown::commonmark()->with(new HeadingPermalinkExtension(
            new HeadingPermalinkPolicy(
                idPrefix: '',
                fragmentPrefix: '',
                htmlClass: '',
                title: '',
            ),
        ));

        self::assertSame(
            '<h1><a id="title" href="#title" aria-hidden="true" title="">¶</a>'
            ."Title</h1>\n",
            $factory->toHtml("# Title\n"),
        );
    }

    public function testSlugCacheInvalidatesAfterAHeadingRename(): void
    {
        $document = Markdown::commonmark()
            ->with(new HeadingPermalinkExtension())
            ->fromString("# Repeat\n\n# Repeat\n");
        $headings = [...$document->headings()];
        $renderer = new HtmlDocumentRenderer();

        self::assertStringContainsString('content-repeat-1', $renderer->renderNode($document->model(), $headings[1]));

        $headings[0]->rename('Other');

        self::assertStringContainsString('content-repeat"', $renderer->renderNode($document->model(), $headings[1]));
        self::assertStringNotContainsString('content-repeat-1', $renderer->renderNode($document->model(), $headings[1]));
    }

    public function testActiveExtensionDoesNothingWhenNoHeadingIsRendered(): void
    {
        $factory = Markdown::commonmark()->with(new HeadingPermalinkExtension());

        Instrumentation::reset();
        self::assertSame("<p>Plain text.</p>\n", $factory->toHtml("Plain text.\n"));
        self::assertSame(0, Instrumentation::$htmlDecoratorInvocations);
        self::assertSame(0, Instrumentation::$inlineParses);
    }

    public function testHeadingTapesAreParsedOnceThenReusedForSlugAndHtml(): void
    {
        $factory = Markdown::commonmark()->with(new HeadingPermalinkExtension());

        Instrumentation::reset();
        $factory->toHtml("# **First**\n\n## *Second*\n");

        self::assertSame(2, Instrumentation::$inlineParses);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidLevels(): iterable
    {
        yield 'minimum below one' => [0, 6];
        yield 'minimum above six' => [7, 7];
        yield 'maximum below one' => [1, 0];
        yield 'maximum above six' => [1, 7];
        yield 'reversed range' => [4, 2];
    }

    #[DataProvider('invalidLevels')]
    public function testRejectsInvalidLevelRanges(int $min, int $max): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        new HeadingPermalinkPolicy(minLevel: $min, maxLevel: $max);
    }

    public function testPolicyLevelAndPrefixHelpersAreExplicit(): void
    {
        $policy = new HeadingPermalinkPolicy(minLevel: 2, maxLevel: 4);

        self::assertFalse($policy->appliesTo(1));
        self::assertTrue($policy->appliesTo(2));
        self::assertTrue($policy->appliesTo(4));
        self::assertFalse($policy->appliesTo(5));
        self::assertSame('content-title', HeadingPermalinkPolicy::prefixed('content', 'title'));
        self::assertSame('title', HeadingPermalinkPolicy::prefixed('', 'title'));
    }
}
