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

namespace Alto\Markdown\Tests\Render;

use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\HtmlSanitizer;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

final class CuratedHtmlPolicyTest extends TestCase
{
    public function testDirectAndDocumentRenderingPreserveCuratedHtmlAndSource(): void
    {
        $source = <<<'MD'
            <h1 id="unsafe" onclick="alert(1)">Raw <em>heading</em></h1>

            Paragraph with <mark title="kept" onclick="alert(1)">raw HTML</mark>.

            <script>alert(1)</script>

            - [x] task
            MD;
        $factory = Markdown::github();
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::curated());
        $document = $factory->fromString($source);

        $direct = $factory->toHtml($source, renderOptions: $options);
        $workspace = $document->toHtml($options);

        self::assertSame($direct, $workspace);
        self::assertSame($source, $document->toMarkdown());
        self::assertStringContainsString('<h1>Raw <em>heading</em></h1>', $direct);
        self::assertStringContainsString('<mark title="kept">raw HTML</mark>', $direct);
        self::assertStringContainsString('<input checked="" disabled="" type="checkbox">', $direct);
        self::assertStringNotContainsString('onclick', $direct);
        self::assertStringNotContainsString('script', $direct);
    }

    public function testCuratedPolicyBypassesGfmTagFilterBeforeFinalSanitization(): void
    {
        $html = Markdown::gfm()->toHtml(
            'before <script>alert(1)</script> after',
            renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
        );

        self::assertSame("<p>before  after</p>\n", $html);
        self::assertStringNotContainsString('&lt;script', $html);
    }

    public function testCuratedPolicyRemovesProcessingInstructions(): void
    {
        $html = Markdown::commonmark()->toHtml(
            "<?unsafe command?>\n",
            renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
        );

        self::assertSame("\n", $html);
    }

    public function testFinalFragmentSanitizationPreservesMarkdownInsideInlineHtml(): void
    {
        $html = Markdown::github()->toHtml(
            'Before <mark title="kept" onclick="alert(1)">**strong**</mark> after.',
            renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
        );

        self::assertSame(
            "<p>Before <mark title=\"kept\"><strong>strong</strong></mark> after.</p>\n",
            $html,
        );
    }

    public function testGeneratedAlertAndCodeClassesSurviveTheCuratedPolicy(): void
    {
        $html = Markdown::github()->toHtml(
            "> [!WARNING]\n> Be **careful**.\n\n```php\necho 1;\n```\n",
            renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
        );

        self::assertSame(
            "<div class=\"markdown-alert markdown-alert-warning\">\n"
            . "<p class=\"markdown-alert-title\">Warning</p>\n"
            . "<p>Be <strong>careful</strong>.</p>\n"
            . "</div>\n"
            . "<pre><code class=\"language-php\">echo 1;\n</code></pre>\n",
            $html,
        );
    }

    public function testNodeAndSectionRenderingApplyTheSameFinalBoundary(): void
    {
        $document = Markdown::github()->fromString(
            "# One\n\n<div onclick=\"alert(1)\"><strong>Safe</strong></div>\n\n# Two\n\nOther.\n",
        );
        $renderer = new HtmlDocumentRenderer();
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::curated());
        $section = $document->section('One');
        $html = $renderer->renderSection($document->model(), $section, $options);
        $node = $document->title();
        self::assertNotNull($node);

        self::assertSame("<h1>One</h1>\n<div><strong>Safe</strong></div>\n", $html);
        self::assertSame("<h1>One</h1>\n", $renderer->renderNode($document->model(), $node, $options));
    }

    public function testAllowedSchemesCanBeNarrowedForGeneratedAndRawLinks(): void
    {
        $policy = HtmlPolicy::curated()->withAllowedSchemes('https');
        $html = Markdown::commonmark()->toHtml(
            "[generated](http://example.com)\n\n<a href=\"http://example.com\">raw</a>\n",
            renderOptions: new RenderOptions(htmlPolicy: $policy),
        );

        self::assertSame("<p><a href=\"\">generated</a></p>\n<p><a>raw</a></p>\n", $html);
    }

    public function testCustomSanitizerReceivesTheCompleteRenderedFragment(): void
    {
        $sanitizer = new RecordingHtmlSanitizer();
        $policy = HtmlPolicy::safe()->withSanitizer($sanitizer);
        $options = new RenderOptions(htmlPolicy: $policy);
        $factory = Markdown::commonmark();
        $source = "Text with <strong>raw</strong>.\n";

        self::assertSame(
            "<sanitized><p>Text with <strong>raw</strong>.</p>\n</sanitized>",
            $factory->toHtml($source, renderOptions: $options),
        );
        self::assertSame(
            "<sanitized><p>Text with <strong>raw</strong>.</p>\n</sanitized>",
            $factory->fromString($source)->toHtml($options),
        );
        self::assertSame(2, $sanitizer->calls);
    }

    public function testSanitizerTimingIsASeparateBalancedStage(): void
    {
        Instrumentation::measure();

        try {
            Markdown::github()->toHtml(
                "<div>safe</div>\n",
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
            );
        } finally {
            Instrumentation::disable();
        }

        self::assertSame(1, Instrumentation::$stageEnters['render-output'] ?? 0);
        self::assertSame(1, Instrumentation::$stageEnters['sanitize-html'] ?? 0);
        self::assertSame(0, Instrumentation::regionDepth());
    }

    public function testSanitizerFailureUnwindsTheTimingStage(): void
    {
        Instrumentation::measure();

        try {
            Markdown::github()->toHtml(
                'content',
                renderOptions: new RenderOptions(
                    htmlPolicy: HtmlPolicy::safe()->withSanitizer(new ThrowingHtmlSanitizer()),
                ),
            );
            self::fail('The sanitizer should have failed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('sanitizer failed', $exception->getMessage());
        } finally {
            Instrumentation::disable();
        }

        self::assertSame(0, Instrumentation::regionDepth());
    }
}

final class RecordingHtmlSanitizer implements HtmlSanitizer
{
    public int $calls = 0;

    public function sanitize(string $html, HtmlPolicy $policy): string
    {
        ++$this->calls;

        return '<sanitized>' . $html . '</sanitized>';
    }

    public function cacheKey(): string
    {
        return 'recording-v1';
    }
}

final readonly class ThrowingHtmlSanitizer implements HtmlSanitizer
{
    public function sanitize(string $html, HtmlPolicy $policy): string
    {
        throw new \RuntimeException('sanitizer failed');
    }

    public function cacheKey(): string
    {
        return 'throwing-v1';
    }
}
