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
use Alto\Markdown\Extension\DefaultAttributes\DefaultAttributesDecorator;
use Alto\Markdown\Extension\DefaultAttributes\DefaultAttributesExtension;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkExtension;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkPolicy;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPolicy;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaultAttributesExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testAddsStaticDefaultsToNativeBlockAndInlineElementsInBothLanes(): void
    {
        $factory = Markdown::commonmark()->with(new DefaultAttributesExtension([
            'atx-heading' => ['class' => 'title', 'id' => 'fallback'],
            'paragraph' => ['class' => ['prose', 'prose', 'content'], 'data-kind' => 'body', 'hidden' => false],
            'link' => ['class' => 'link', 'target' => '_self'],
            'image' => ['class' => 'media', 'loading' => 'lazy'],
            'fenced-code' => ['class' => ['code', 'copy'], 'data-copy' => true],
            'list' => ['class' => 'items', 'start' => '99'],
            'list-item' => ['class' => 'item'],
            'thematic-break' => ['class' => 'rule', 'hidden' => true],
        ]));
        $source = "# Title\n\n"
            ."Read [Alto](https://alto.example) and ![Logo](logo.png).\n\n"
            ."```php\ncode();\n```\n\n"
            ."3. Item\n\n"
            ."---\n";
        $expected = '<h1 class="title" id="fallback">Title</h1>'."\n"
            .'<p class="prose content" data-kind="body">Read '
            .'<a href="https://alto.example" class="link" target="_self">Alto</a> and '
            .'<img src="logo.png" alt="Logo" class="media" loading="lazy" />.</p>'."\n"
            .'<pre><code class="code copy language-php" data-copy>code();'."\n"
            .'</code></pre>'."\n"
            .'<ol start="3" class="items">'."\n"
            .'<li class="item">Item</li>'."\n"
            .'</ol>'."\n"
            .'<hr class="rule" hidden />'."\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());
        self::assertSame($source, $factory->fromString($source)->toMarkdown());
    }

    public function testNativeAttributesWinWhileDefaultClassesMergeFirst(): void
    {
        $factory = Markdown::commonmark()->with(new DefaultAttributesExtension([
            'link' => [
                'href' => 'https://fallback.example',
                'title' => 'Fallback',
                'class' => ['default', 'link'],
            ],
            'image' => [
                'src' => 'fallback.png',
                'alt' => 'Fallback',
                'title' => 'Fallback',
            ],
            'list' => ['start' => '99'],
            'fenced-code' => ['class' => ['default', 'code', 'language-php']],
        ]));

        self::assertSame(
            '<p><a href="https://actual.example" title="Actual" class="default link">Link</a> '
            .'<img src="actual.png" alt="Actual" title="Actual" /></p>'."\n"
            .'<ol start="4">'."\n"
            .'<li>Item</li>'."\n"
            .'</ol>'."\n"
            .'<pre><code class="default code language-php">code'."\n"
            .'</code></pre>'."\n",
            $factory->toHtml(
                '[Link](https://actual.example "Actual") '
                ."![Actual](actual.png \"Actual\")\n\n"
                ."4. Item\n\n"
                ."```php\ncode\n```\n",
            ),
        );
    }

    public function testAttributeNamesInsideNativeValuesAreNotMistakenForAttributes(): void
    {
        $factory = Markdown::commonmark()->with(new DefaultAttributesExtension([
            'image' => ['class' => 'media', 'loading' => 'lazy'],
        ]));

        self::assertSame(
            '<p><img src="logo.png" alt="Logo class=bad loading=bad" '
            .'class="media" loading="lazy" /></p>'."\n",
            $factory->toHtml("![Logo class=bad loading=bad](logo.png)\n"),
        );
    }

    public function testComposesWithHeadingPermalinksAndExternalLinks(): void
    {
        $factory = Markdown::commonmark()->with(
            new DefaultAttributesExtension([
                'atx-heading' => ['id' => 'fallback', 'class' => 'default-heading'],
                'link' => ['class' => 'default-link', 'target' => '_self', 'rel' => 'author'],
            ]),
            new HeadingPermalinkExtension(new HeadingPermalinkPolicy(
                applyIdToHeading: true,
                headingClass: 'anchored',
            )),
            new ExternalLinkExtension(new ExternalLinkPolicy(openInNewWindow: true)),
        );

        self::assertSame(
            '<h1 id="content-title" class="default-heading anchored">'
            .'<a href="#content-title" class="heading-permalink" aria-hidden="true" title="Permalink">¶</a>'
            .'Title</h1>'."\n"
            .'<p><a rel="noopener noreferrer" target="_blank" '
            .'href="https://outside.example" class="default-link">Outside</a></p>'."\n",
            $factory->toHtml("# Title\n\n[Outside](https://outside.example)\n"),
        );
    }

    public function testUrlDefaultsUseTheActiveHtmlPolicy(): void
    {
        $factory = Markdown::commonmark()->with(new DefaultAttributesExtension([
            'paragraph' => [
                'href' => 'javascript:alert(1)',
                'src' => 'data:text/html,unsafe',
            ],
        ]));

        self::assertSame(
            "<p href=\"\" src=\"\">Text.</p>\n",
            $factory->toHtml("Text.\n"),
        );
        self::assertSame(
            '<p href="javascript:alert(1)" src="data:text/html,unsafe">Text.</p>'."\n",
            $factory->toHtml(
                "Text.\n",
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::spec()),
            ),
        );
    }

    public function testTrustedStyleAndEventAttributesNeedCuratedPolicyForSanitizing(): void
    {
        $factory = Markdown::commonmark()->with(new DefaultAttributesExtension([
            'paragraph' => [
                'style' => 'color: red',
                'onclick' => 'alert("x")',
            ],
        ]));
        $trusted = '<p style="color: red" onclick="alert(&quot;x&quot;)">Text.</p>'."\n";

        self::assertSame($trusted, $factory->toHtml("Text.\n"));
        self::assertSame(
            $trusted,
            $factory->toHtml(
                "Text.\n",
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::spec()),
            ),
        );

        if (class_exists(\Dom\HTMLDocument::class)) {
            $curated = $factory->toHtml(
                "Text.\n",
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
            );

            self::assertStringNotContainsString('style=', $curated);
            self::assertStringNotContainsString('onclick=', $curated);
        }
    }

    public function testSupportsProfileSpecificGeneratedElements(): void
    {
        $gfm = Markdown::gfm()->with(new DefaultAttributesExtension([
            'gfm:table' => ['class' => 'data'],
            'strikethrough' => ['class' => 'removed'],
        ]));
        $github = Markdown::github()->with(new DefaultAttributesExtension([
            'github:alert' => ['class' => ['notice', 'notice']],
        ]));

        self::assertStringStartsWith(
            '<table class="data">',
            $gfm->toHtml("| A |\n| - |\n| ~~B~~ |\n"),
        );
        self::assertStringContainsString('<del class="removed">B</del>', $gfm->toHtml("~~B~~\n"));
        self::assertStringStartsWith(
            '<div class="notice markdown-alert markdown-alert-note">',
            $github->toHtml("> [!NOTE]\n> Body.\n"),
        );
    }

    public function testUnmatchedDefaultAttributesDispatchNothing(): void
    {
        $factory = Markdown::commonmark()->with(new DefaultAttributesExtension([
            'thematic-break' => ['class' => 'rule'],
        ]));

        Instrumentation::reset();
        self::assertSame("<p>Plain.</p>\n", $factory->toHtml("Plain.\n"));
        self::assertSame(0, Instrumentation::$htmlDecoratorInvocations);
    }

    public function testDefaultsDoNotCreateMissingTightParagraphElements(): void
    {
        $factory = Markdown::commonmark()->with(new DefaultAttributesExtension([
            'paragraph' => ['class' => 'prose'],
        ]));

        self::assertSame(
            "<ul>\n<li>Item</li>\n</ul>\n",
            $factory->toHtml("- Item\n"),
        );
    }

    public function testInternalDecoratorLeavesMissingElementsUnchanged(): void
    {
        $decorator = new DefaultAttributesDecorator(['class' => 'prose'], null);
        $context = new HtmlNodeOutputContext('paragraph', null, 'Plain.', HtmlPolicy::safe());

        self::assertSame('Plain.', $decorator->decorate($context, 'Plain.'));
    }

    public function testBooleanAndEmptyClassDefaultsRemainConservative(): void
    {
        $factory = Markdown::commonmark()->with(new DefaultAttributesExtension([
            'paragraph' => ['class' => false],
            'fenced-code' => ['class' => true],
        ]));

        self::assertSame(
            "<p>Text.</p>\n"
            .'<pre><code class="language-php">code'."\n"
            .'</code></pre>'."\n",
            $factory->toHtml("Text.\n\n```php\ncode\n```\n"),
        );
    }

    public function testEmptyAttributeMapCompilesNoDecorator(): void
    {
        $factory = Markdown::commonmark()->with(new DefaultAttributesExtension([
            'paragraph' => [],
        ]));

        Instrumentation::reset();
        self::assertSame("<p>Text.</p>\n", $factory->toHtml("Text.\n"));
        self::assertSame(0, Instrumentation::$htmlDecoratorInvocations);
    }

    public function testRuntimeValidationRejectsValuesOutsideTheDeclaredContract(): void
    {
        $class = new \ReflectionClass(DefaultAttributesExtension::class);

        try {
            $class->newInstance(['paragraph' => ['class' => [1]]]);
            self::fail('A non-string class must fail.');
        } catch (InvalidMarkdownArgumentException $exception) {
            self::assertStringContainsString('classes', $exception->getMessage());
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('must be a string or boolean');

        $class->newInstance(['paragraph' => ['data-count' => 1]]);
    }

    /**
     * @return iterable<string, array{array<string, array<string, bool|string|list<string>>>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'non-element kind' => [['text' => ['class' => 'x']], 'unsupported native element kind'];
        yield 'raw HTML kind' => [['html-inline' => ['class' => 'x']], 'unsupported native element kind'];
        yield 'invalid attribute name' => [['paragraph' => ['not valid' => 'x']], 'attribute name'];
        yield 'duplicate casing' => [['paragraph' => ['CLASS' => 'a', 'class' => 'b']], 'different casing'];
        yield 'list outside class' => [['link' => ['rel' => ['author']]], 'Only the class attribute'];
    }

    /**
     * @param array<string, array<string, bool|string|list<string>>> $configuration
     */
    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfigurations(array $configuration, string $message): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage($message);

        new DefaultAttributesExtension($configuration);
    }

    public function testRejectsAKindMissingFromTheSelectedProfile(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('unknown or non-native node kind "gfm:table"');

        Markdown::commonmark()->with(new DefaultAttributesExtension([
            'gfm:table' => ['class' => 'data'],
        ]));
    }
}
