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
use Alto\Markdown\Extension\Attributes\AttributesExtension;
use Alto\Markdown\Extension\Attributes\AttributesPolicy;
use Alto\Markdown\Extension\DefaultAttributes\DefaultAttributesExtension;
use Alto\Markdown\Extension\Tabs\TabsExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

final class AttributesExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::disable();
        Instrumentation::reset();
    }

    public function testBlockAttributesTargetTheNextOrPreviousSiblingInBothLanes(): void
    {
        $factory = Markdown::commonmark()->with(new AttributesExtension(new AttributesPolicy([
            'id',
            'class',
            'title',
        ])));
        $source = "{#intro .lead title=\"Welcome home\"}\n"
            . "{.wide .lead}\n"
            . "# Hello\n\n"
            . "> Quote\n"
            . "> {: .quoted}\n\n"
            . "Paragraph\n"
            . "{: .after}\n";
        $expected = '<h1 class="lead wide" id="intro" title="Welcome home">Hello</h1>' . "\n"
            . "<blockquote>\n"
            . '<p class="quoted">Quote</p>' . "\n"
            . "</blockquote>\n"
            . '<p class="after">Paragraph</p>' . "\n";

        self::assertSame($expected, $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame($source, $document->toMarkdown(new RenderOptions()));
        self::assertCount(3, $document->query()->kind(AttributesExtension::BLOCK_KIND)->get()->all());
    }

    public function testInlineAttributesAttachOnlyToThePreviousRenderedElement(): void
    {
        $factory = Markdown::commonmark()->with(new AttributesExtension(new AttributesPolicy([
            'id',
            'class',
            'title',
            'disabled',
        ])));
        $source = '*red*{.accent title=Warm}, **bold**{#strong}, '
            . '`code`{.token}, ![Logo](logo.png){.media disabled=true}, '
            . 'and plain{.literal}.';
        $expected = '<em class="accent" title="Warm">red</em>, '
            . '<strong id="strong">bold</strong>, '
            . '<code class="token">code</code>, '
            . '<img src="logo.png" alt="Logo" class="media" disabled />, '
            . 'and plain{.literal}.';

        self::assertSame($expected, $factory->toInlineHtml($source));
        self::assertSame("<p>{$expected}</p>\n", $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame("<p>{$expected}</p>\n", $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
    }

    public function testDefaultsAllowOnlyIdsAndClasses(): void
    {
        $factory = Markdown::commonmark()->with(new AttributesExtension());
        $source = "{#title .hero title=Ignored data-x=Ignored}\n# Hello\n\n"
            . "*text*{.accent title=Ignored}\n";

        self::assertSame(
            '<h1 class="hero" id="title">Hello</h1>' . "\n"
            . '<p><em class="accent">text</em></p>' . "\n",
            $factory->toHtml($source),
        );
    }

    public function testNativeAttributesWinAndClassesMergeWithoutDuplicates(): void
    {
        $factory = Markdown::commonmark()->with(new AttributesExtension(new AttributesPolicy([
            'class',
            'href',
            'src',
            'title',
        ])));

        self::assertSame(
            '<a href="/actual" title="Actual" class="one two">Link</a>',
            $factory->toInlineHtml('[Link](/actual "Actual"){.one class="one two" href=/other title=Other}'),
        );
        self::assertSame(
            '<img src="actual.png" alt="Actual" class="media" />',
            $factory->toInlineHtml('![Actual](actual.png){src=other.png .media}'),
        );
    }

    public function testSourceAttributesOverrideConfiguredDefaultsWhileClassesCompose(): void
    {
        $factory = Markdown::commonmark()->with(
            new DefaultAttributesExtension([
                'atx-heading' => ['id' => 'fallback', 'class' => 'default'],
            ]),
            new AttributesExtension(),
        );

        self::assertSame(
            '<h1 class="default source" id="source">Heading</h1>' . "\n",
            $factory->toHtml("{#source .source}\n# Heading\n"),
        );
    }

    public function testBlockAttributesComposeWithAnotherExtensionRenderer(): void
    {
        $factory = Markdown::commonmark()->with(
            new AttributesExtension(),
            new TabsExtension(),
        );
        $source = "{.source-tabs}\n"
            . "@tabs\n"
            . "@tab One\n"
            . "Body.\n"
            . "@endtabs\n";

        $html = $factory->toHtml($source);

        self::assertStringStartsWith(
            '<div class="markdown-tabs source-tabs" id="markdown-tabs-1">',
            $html,
        );
        self::assertSame($html, $factory->fromString($source)->toHtml());
    }

    public function testUrlAttributesFollowTheActiveHtmlPolicy(): void
    {
        $factory = Markdown::commonmark()->with(new AttributesExtension(new AttributesPolicy([
            'href',
            'src',
        ])));
        $source = '*unsafe*{href="java&#x73;cript:alert(1)" src=javascript:alert(2)}';

        self::assertSame(
            '<em href="java&amp;#x73;cript:alert(1)">unsafe</em>',
            $factory->toInlineHtml($source),
        );
        self::assertSame(
            '<em href="java&amp;#x73;cript:alert(1)" src="javascript:alert(2)">unsafe</em>',
            $factory->toInlineHtml(
                $source,
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::spec()),
            ),
        );
    }

    public function testCuratedRenderingCanFurtherRestrictAllowedSourceAttributes(): void
    {
        if (!class_exists(\Dom\HTMLDocument::class)) {
            self::markTestSkipped('The DOM HTML5 extension is unavailable.');
        }

        $factory = Markdown::commonmark()->with(new AttributesExtension(new AttributesPolicy([
            'title',
            'data-private',
        ])));
        $html = $factory->toHtml(
            "{title=Visible data-private=secret}\n# Heading\n",
            renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
        );

        self::assertStringContainsString('title="Visible"', $html);
        self::assertStringNotContainsString('data-private', $html);
    }

    public function testMalformedAndBoundedListsRemainLiteral(): void
    {
        $policy = new AttributesPolicy(maxAttributes: 2, maxListBytes: 32, maxValueBytes: 8);
        $factory = Markdown::commonmark()->with(new AttributesExtension($policy));

        self::assertSame("<p>{.}</p>\n<h1>Heading</h1>\n", $factory->toHtml("{.}\n# Heading\n"));
        self::assertSame('<p><em>x</em>{.one .two .three}</p>' . "\n", $factory->toHtml("*x*{.one .two .three}\n"));
        self::assertSame(
            '<p><em>x</em>{title=&quot;too-long-value&quot;}</p>' . "\n",
            $factory->toHtml("*x*{title=\"too-long-value\"}\n"),
        );
        self::assertSame('<p><em>x</em>{}</p>' . "\n", $factory->toHtml("*x*{}\n"));
    }

    public function testPolicyRejectsInvalidDangerousDuplicateAndOutOfRangeConfiguration(): void
    {
        $invalid = [
            static fn(): AttributesPolicy => new AttributesPolicy(['onclick']),
            static fn(): AttributesPolicy => new AttributesPolicy(['CLASS', 'class']),
            static fn(): AttributesPolicy => new AttributesPolicy(['bad name']),
            static fn(): AttributesPolicy => new AttributesPolicy(maxAttributes: 0),
            static fn(): AttributesPolicy => new AttributesPolicy(maxListBytes: 15),
            static fn(): AttributesPolicy => new AttributesPolicy(maxValueBytes: 1025),
        ];
        $caught = 0;

        foreach ($invalid as $create) {
            try {
                $create();
                self::fail('Expected invalid attribute policy configuration.');
            } catch (InvalidMarkdownArgumentException) {
                ++$caught;
            }
        }

        self::assertSame(\count($invalid), $caught);
    }

    public function testInactiveCoreAndUntriggeredExtensionAvoidExtensionDispatch(): void
    {
        $source = "Plain paragraph without braces.\n";
        $extension = new AttributesExtension();

        self::assertSame([], $extension->blockConstructs());
        self::assertSame([], $extension->nativeHtmlBlockRenderers());
        self::assertSame([], $extension->nativeMarkdownBlockPrinters());

        Instrumentation::measure();
        self::assertSame("<p>Plain paragraph without braces.</p>\n", Markdown::commonmark()->toHtml($source));
        $coreBlockAttempts = Instrumentation::$blockTryStartCalls;
        $coreInlineAttempts = Instrumentation::$extensionInlineParserAttempts;

        Instrumentation::reset();
        self::assertSame(
            "<p>Plain paragraph without braces.</p>\n",
            Markdown::commonmark()->with(new AttributesExtension())->toHtml($source),
        );

        self::assertSame($coreBlockAttempts, Instrumentation::$blockTryStartCalls);
        self::assertSame($coreInlineAttempts, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame("<p>{.lead}</p>\n", Markdown::commonmark()->toHtml("{.lead}\n"));
    }
}
