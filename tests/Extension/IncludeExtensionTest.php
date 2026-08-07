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

use Alto\Markdown\Exception\BlockCountLimitException;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\ResourceDeniedException;
use Alto\Markdown\Extension\Attributes\AttributesExtension;
use Alto\Markdown\Extension\Import\ImportExtension;
use Alto\Markdown\Extension\Include\IncludeExtension;
use Alto\Markdown\Extension\Include\IncludePolicy;
use Alto\Markdown\Extension\Tabs\TabsExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\HtmlSanitizer;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Resource\ResolvedResource;
use Alto\Markdown\Resource\ResourceRequest;
use Alto\Markdown\Resource\ResourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IncludeExtensionTest extends TestCase
{
    public function testResolvesRecursiveMarkdownRelativeToEachParentOncePerParse(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|guide.md' => ['guide', "# Guide\n\n@include \"parts/setup.md\"\n"],
            'guide|parts/setup.md' => ['setup', "## Setup\n\n@include \"detail.md\"\n"],
            'setup|detail.md' => ['detail', "Install **Alto**.\n"],
        ]);
        $factory = Markdown::commonmark()->with(new IncludeExtension($resolver));
        $source = "Before.\n\n@include \"guide.md\"\n\nAfter.\n";
        $expected = "<p>Before.</p>\n"
            ."<h1>Guide</h1>\n"
            ."<h2>Setup</h2>\n"
            ."<p>Install <strong>Alto</strong>.</p>\n"
            ."<p>After.</p>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertCount(3, $resolver->requests);
        self::assertNull($resolver->requests[0]->originId);
        self::assertSame('guide', $resolver->requests[1]->originId);
        self::assertSame('setup', $resolver->requests[2]->originId);

        $document = $factory->fromString($source);
        self::assertCount(6, $resolver->requests);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($expected, $document->toHtml());
        self::assertCount(6, $resolver->requests);
        self::assertSame($source, $document->toMarkdown());
        self::assertSame(
            "Before\\.\n\n@include \"guide.md\"\n\nAfter\\.\n",
            $document->toMarkdown(new RenderOptions()),
        );
        self::assertCount(1, $document->query()->kind('include:block')->get()->all());
    }

    public function testIncludedFragmentsUseTheCurrentCompiledProfile(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|fragment.md' => ['fragment', <<<'MARKDOWN'
                {.included}
                | Name | Value |
                | --- | --- |
                | Alto | **fast** |

                @tabs
                @tab One
                Nested.
                @endtabs
                MARKDOWN],
        ]);
        $factory = Markdown::gfm()->with(
            new IncludeExtension($resolver),
            new AttributesExtension(),
            new TabsExtension(),
        );

        $html = $factory->toHtml('@include "fragment.md"');

        self::assertStringStartsWith('<table class="included">', $html);
        self::assertStringContainsString('<strong>fast</strong>', $html);
        self::assertStringContainsString('<div class="markdown-tabs"', $html);
        self::assertCount(1, $resolver->requests);
    }

    public function testIncludedHtmlUsesTheCurrentHtmlPolicy(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|unsafe.md' => ['unsafe', "<h1>Allowed</h1><script>alert(1)</script>\n"],
        ]);
        $factory = Markdown::commonmark()->with(new IncludeExtension($resolver));
        $source = '@include "unsafe.md"';

        self::assertSame(
            "&lt;h1&gt;Allowed&lt;/h1&gt;&lt;script&gt;alert(1)&lt;/script&gt;\n",
            $factory->toHtml($source),
        );
        self::assertSame(
            "<h1>Allowed</h1><script>alert(1)</script>\n",
            $factory->toHtml(
                $source,
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::spec()),
            ),
        );

        if (class_exists(\Dom\HTMLDocument::class)) {
            self::assertSame(
                "<h1>Allowed</h1>\n",
                $factory->toHtml(
                    $source,
                    renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
                ),
            );
        }
    }

    public function testFinalHtmlSanitizerRunsOnceForTheCompleteOutput(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|part.md' => ['part', "<b>Included</b>\n"],
        ]);
        $sanitizer = new IncludeRecordingSanitizer();
        $policy = HtmlPolicy::safe()->withSanitizer($sanitizer);

        $html = Markdown::commonmark()
            ->with(new IncludeExtension($resolver))
            ->toHtml(
                "Before.\n\n@include \"part.md\"\n\nAfter.\n",
                renderOptions: new RenderOptions(htmlPolicy: $policy),
            );

        self::assertCount(1, $sanitizer->inputs);
        self::assertSame($html, $sanitizer->inputs[0]);
        self::assertStringContainsString('<b>Included</b>', $html);
    }

    public function testDiscoveryIgnoresDirectivesInsideFencedCodeAndRawHtml(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|main.md' => ['main', <<<'MARKDOWN'
                ```text
                @include "secret.md"
                ```

                <script>
                @include "secret.md"
                </script>
                MARKDOWN],
            'main|secret.md' => ['secret', 'SECRET'],
        ]);
        $factory = Markdown::commonmark()->with(new IncludeExtension($resolver));

        $html = $factory->toHtml('@include "main.md"');

        self::assertCount(1, $resolver->requests);
        self::assertStringContainsString('@include &quot;secret.md&quot;', $html);
        self::assertStringNotContainsString('SECRET', $html);
    }

    public function testDirectivesInsideCustomContainersRemainLiteral(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|secret.md' => ['secret', 'SECRET'],
        ]);
        $factory = Markdown::commonmark()->with(
            new IncludeExtension($resolver),
            new TabsExtension(),
        );
        $source = <<<'MARKDOWN'
            @tabs
            @tab One
            @include "secret.md"
            @endtabs
            MARKDOWN;

        $html = $factory->toHtml($source);

        self::assertCount(0, $resolver->requests);
        self::assertStringContainsString('@include &quot;secret.md&quot;', $html);
        self::assertStringNotContainsString('SECRET', $html);
    }

    public function testUndiscoveredDirectiveCannotRestartTheIncludeParser(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|main.md' => ['main', "@import \"code.php\"\n@include \"secret.md\"\n"],
            'root|code.php' => ['code', "<?php echo 'safe';\n"],
            'root|secret.md' => ['secret', 'SECRET'],
        ]);
        $factory = Markdown::commonmark()->with(
            new IncludeExtension($resolver),
            new ImportExtension($resolver),
        );

        $html = $factory->toHtml('@include "main.md"');

        self::assertCount(2, $resolver->requests);
        self::assertSame('include', $resolver->requests[0]->purpose);
        self::assertSame('import', $resolver->requests[1]->purpose);
        self::assertStringContainsString('@include &quot;secret.md&quot;', $html);
        self::assertStringNotContainsString('SECRET', $html);
    }

    public function testRepeatedNonCyclicResourcesAreAllowedAndCounted(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|main.md' => ['main', "@include \"part.md\"\n\n@include \"part.md\"\n"],
            'main|part.md' => ['part', "Repeated.\n"],
        ]);
        $factory = Markdown::commonmark()->with(new IncludeExtension($resolver));

        $html = $factory->toHtml('@include "main.md"');

        self::assertSame(2, substr_count($html, '<p>Repeated.</p>'));
        self::assertCount(3, $resolver->requests);
    }

    public function testRejectsCyclesByResolvedResourceId(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|a.md' => ['a', "@include \"b.md\"\n"],
            'a|b.md' => ['b', "@include \"a.md\"\n"],
            'b|a.md' => ['a', "@include \"b.md\"\n"],
        ]);

        $this->expectException(ResourceDeniedException::class);
        $this->expectExceptionMessage('cycle');

        Markdown::commonmark()
            ->with(new IncludeExtension($resolver))
            ->toHtml('@include "a.md"');
    }

    public function testRejectsDepthResourceAndAggregateByteOverruns(): void
    {
        $depth = new IncludeFixtureResolver([
            'root|a.md' => ['a', "@include \"b.md\"\n"],
            'a|b.md' => ['b', "@include \"c.md\"\n"],
            'b|c.md' => ['c', 'Too deep.'],
        ]);

        try {
            Markdown::commonmark()
                ->with(new IncludeExtension($depth, new IncludePolicy(maxDepth: 2)))
                ->toHtml('@include "a.md"');
            self::fail('Expected the include depth limit.');
        } catch (ResourceDeniedException $error) {
            self::assertStringContainsString('depth exceeds 2', $error->getMessage());
            self::assertCount(2, $depth->requests);
        }

        $resources = new IncludeFixtureResolver([
            'root|a.md' => ['a', "@include \"b.md\"\n"],
            'a|b.md' => ['b', "@include \"c.md\"\n"],
            'b|c.md' => ['c', 'Too many.'],
        ]);

        try {
            Markdown::commonmark()
                ->with(new IncludeExtension($resources, new IncludePolicy(maxResources: 2)))
                ->toHtml('@include "a.md"');
            self::fail('Expected the include resource limit.');
        } catch (ResourceDeniedException $error) {
            self::assertStringContainsString('exceeds 2 resources', $error->getMessage());
            self::assertCount(2, $resources->requests);
        }

        $bytes = new IncludeFixtureResolver([
            'root|a.md' => ['a', "@include \"b.md\"\n"],
            'a|b.md' => ['b', '1234567890'],
        ]);

        $this->expectException(ResourceDeniedException::class);
        $this->expectExceptionMessage('exceeds 25 bytes');

        Markdown::commonmark()
            ->with(new IncludeExtension($bytes, new IncludePolicy(maxExpandedBytes: 25)))
            ->toHtml('@include "a.md"');
    }

    public function testIncludedMarkdownKeepsItsOwnParserBudgets(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|blocks.md' => ['blocks', "One.\n\nTwo.\n"],
        ]);

        $this->expectException(BlockCountLimitException::class);

        Markdown::commonmark()
            ->with(new IncludeExtension($resolver, new IncludePolicy(maxBlockCount: 1)))
            ->toHtml('@include "blocks.md"');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDirectives(): iterable
    {
        yield 'unquoted' => ['@include file.md'];
        yield 'empty' => ['@include ""'];
        yield 'leading space' => [' @include "file.md"'];
        yield 'nested quote' => ['> @include "file.md"'];
        yield 'extra option' => ['@include "file.md" {lines: 1}'];
        yield 'similar name' => ['@included "file.md"'];
        yield 'long directive' => ['@include "'.str_repeat('x', 4_096).'"'];
    }

    #[DataProvider('invalidDirectives')]
    public function testInvalidDirectivesRemainLiteralWithoutIo(string $source): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|file.md' => ['file', 'Secret.'],
        ]);

        $html = Markdown::commonmark()
            ->with(new IncludeExtension($resolver))
            ->toHtml($source);

        self::assertCount(0, $resolver->requests);
        self::assertStringNotContainsString('Secret.', $html);
    }

    public function testDoesNotInterruptParagraphsOrActivateWithoutTheExtension(): void
    {
        $resolver = new IncludeFixtureResolver([
            'root|file.md' => ['file', 'Secret.'],
        ]);
        $source = "Paragraph\n@include \"file.md\"\n";

        self::assertSame(
            "<p>Paragraph\n@include &quot;file.md&quot;</p>\n",
            Markdown::commonmark()->with(new IncludeExtension($resolver))->toHtml($source),
        );
        self::assertCount(0, $resolver->requests);
        self::assertSame(
            "<p>@include &quot;file.md&quot;</p>\n",
            Markdown::commonmark()->toHtml("@include \"file.md\"\n"),
        );
    }

    public function testPolicyRejectsInvalidLimits(): void
    {
        $rejected = 0;

        foreach ([
            static fn (): IncludePolicy => new IncludePolicy(maxDepth: 0),
            static fn (): IncludePolicy => new IncludePolicy(maxDepth: 65),
            static fn (): IncludePolicy => new IncludePolicy(maxResources: 0),
            static fn (): IncludePolicy => new IncludePolicy(maxResources: 10_001),
            static fn (): IncludePolicy => new IncludePolicy(maxExpandedBytes: 0),
            static fn (): IncludePolicy => new IncludePolicy(maxExpandedBytes: \PHP_INT_MAX),
            static fn (): IncludePolicy => new IncludePolicy(maxNestingDepth: 0),
            static fn (): IncludePolicy => new IncludePolicy(maxBlockCount: 0),
            static fn (): IncludePolicy => new IncludePolicy(maxInlineCount: 0),
            static fn (): IncludePolicy => new IncludePolicy(maxReferenceCount: 0),
        ] as $create) {
            try {
                $create();
                self::fail('Expected invalid include policy configuration.');
            } catch (InvalidMarkdownArgumentException) {
                ++$rejected;
            }
        }

        self::assertSame(10, $rejected);
    }
}

final class IncludeFixtureResolver implements ResourceResolver
{
    /**
     * @var list<ResourceRequest>
     */
    public array $requests = [];

    /**
     * @param array<string, array{string, string}> $resources
     */
    public function __construct(private array $resources)
    {
    }

    public function resolve(ResourceRequest $request): ResolvedResource
    {
        $this->requests[] = $request;
        $key = ($request->originId ?? 'root').'|'.$request->reference;
        [$id, $bytes] = $this->resources[$key] ?? throw new \LogicException('Missing fixture resource '.$key);

        return new ResolvedResource($id, $bytes);
    }
}

final class IncludeRecordingSanitizer implements HtmlSanitizer
{
    /**
     * @var list<string>
     */
    public array $inputs = [];

    public function sanitize(string $html, HtmlPolicy $policy): string
    {
        unset($policy);
        $this->inputs[] = $html;

        return $html;
    }

    public function cacheKey(): string
    {
        return 'include-recording';
    }
}
