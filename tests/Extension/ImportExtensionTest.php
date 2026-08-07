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

use Alto\Markdown\Exception\ResourceDeniedException;
use Alto\Markdown\Extension\Import\ImportExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Resource\ResolvedResource;
use Alto\Markdown\Resource\ResourceRequest;
use Alto\Markdown\Resource\ResourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportExtensionTest extends TestCase
{
    public function testImportsEscapedCodeOnceDuringParseAndPreservesTheDirective(): void
    {
        $resolver = new ImportFixtureResolver([
            'snippet.txt' => "<tag>&\n",
        ]);
        $factory = Markdown::commonmark()->with(new ImportExtension($resolver));
        $source = "@import \"snippet.txt\"\n";
        $expected = "<pre><code>&lt;tag&gt;&amp;\n</code></pre>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertCount(1, $resolver->requests);

        $document = $factory->fromString($source);
        self::assertCount(2, $resolver->requests);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($expected, $document->toHtml());
        self::assertCount(2, $resolver->requests);
        self::assertSame($source, $document->toMarkdown());
        self::assertSame($source, $document->toMarkdown(new RenderOptions()));
        self::assertCount(1, $document->query()->kind('import:block')->get()->all());

        $request = $resolver->requests[0];
        self::assertSame('snippet.txt', $request->reference);
        self::assertSame('import', $request->purpose);
        self::assertNull($request->originId);
    }

    public function testSelectsInclusiveLinesIndentsAndAddsTheLanguageClass(): void
    {
        $resolver = new ImportFixtureResolver([
            'snippet.php' => "<?php\r\nline2\rline3\nline4",
        ]);
        $factory = Markdown::commonmark()->with(new ImportExtension($resolver));

        $html = $factory->toHtml(
            '@import "snippet.php" {lines: 2-4, lang: php, indent: 2}',
        );

        self::assertSame(
            "<pre><code class=\"language-php\">  line2\r  line3\n  line4</code></pre>\n",
            $html,
        );
    }

    public function testSupportsSingleLinesAndRangesBeyondTheResource(): void
    {
        $factory = Markdown::commonmark()->with(new ImportExtension(
            new ImportFixtureResolver(['snippet.txt' => "one\r\ntwo\nthree"]),
        ));

        self::assertSame(
            "<pre><code>two</code></pre>\n",
            $factory->toHtml('@import "snippet.txt" {lines: 2}'),
        );
        self::assertSame(
            "<pre><code>three</code></pre>\n",
            $factory->toHtml('@import "snippet.txt" {lines: 3-99}'),
        );
        self::assertSame(
            "<pre><code></code></pre>\n",
            $factory->toHtml('@import "snippet.txt" {lines: 99}'),
        );
    }

    public function testSelectsASmallRangeWithoutMaterializingEveryResourceLine(): void
    {
        $line = '0123456789abcdefgh';
        $resource = str_repeat($line."\r\n", 5_000)
            ."target one\r\ntarget two\r\ntarget three\r\n"
            .str_repeat($line."\r\n", 5_000);
        $factory = Markdown::commonmark()->with(new ImportExtension(
            new ImportFixtureResolver(['medium.txt' => $resource]),
        ));

        self::assertGreaterThan(200_000, \strlen($resource));
        self::assertSame(
            "<pre><code>target one\r\ntarget two\r\ntarget three</code></pre>\n",
            $factory->toHtml('@import "medium.txt" {lines: 5001-5003}'),
        );
    }

    public function testAllowsRepeatedImportsBecauseContentIsNeverRecursivelyParsed(): void
    {
        $resolver = new ImportFixtureResolver(['snippet.txt' => 'same']);
        $factory = Markdown::commonmark()->with(new ImportExtension($resolver));

        $html = $factory->toHtml(
            "@import \"snippet.txt\"\n\n@import \"snippet.txt\"\n",
        );

        self::assertSame(2, substr_count($html, '<pre><code>same</code></pre>'));
        self::assertCount(2, $resolver->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDirectives(): iterable
    {
        yield 'unquoted path' => ['@import snippet.md'];
        yield 'leading space' => [' @import "snippet.md"'];
        yield 'nested in block quote' => ['> @import "snippet.md"'];
        yield 'missing path' => ['@import'];
        yield 'unknown option' => ['@import "snippet.md" {unknown: value}'];
        yield 'malformed option' => ['@import "snippet.md" {lines 1-2}'];
        yield 'duplicate option' => ['@import "snippet.md" {lang: php, lang: js}'];
        yield 'zero line' => ['@import "snippet.md" {lines: 0}'];
        yield 'descending range' => ['@import "snippet.md" {lines: 3-2}'];
        yield 'line above bound' => ['@import "snippet.md" {lines: 1000001}'];
        yield 'invalid language' => ['@import "snippet.md" {lang: php html}'];
        yield 'long language' => ['@import "snippet.md" {lang: '.str_repeat('x', 65).'}'];
        yield 'negative indent' => ['@import "snippet.md" {indent: -1}'];
        yield 'indent above bound' => ['@import "snippet.md" {indent: 33}'];
        yield 'duplicate comma' => ['@import "snippet.md" {lines: 1,, lang: php}'];
        yield 'long directive' => ['@import "'.str_repeat('x', 4_096).'"'];
        yield 'similar name' => ['@important "snippet.md"'];
    }

    #[DataProvider('invalidDirectives')]
    public function testInvalidOrUnmatchedDirectivesRemainLiteralWithoutIo(string $source): void
    {
        $resolver = new ImportFixtureResolver(['snippet.md' => 'secret']);
        $factory = Markdown::commonmark()->with(new ImportExtension($resolver));

        $html = $factory->toHtml($source);

        self::assertCount(0, $resolver->requests);
        self::assertStringNotContainsString('secret', $html);
        self::assertStringNotContainsString('<pre><code>secret', $html);
    }

    public function testDoesNotInterruptAParagraphOrActivateWithoutTheExtension(): void
    {
        $resolver = new ImportFixtureResolver(['snippet.md' => 'secret']);
        $source = "Paragraph\n@import \"snippet.md\"\n";

        self::assertSame(
            "<p>Paragraph\n@import &quot;snippet.md&quot;</p>\n",
            Markdown::commonmark()->with(new ImportExtension($resolver))->toHtml($source),
        );
        self::assertCount(0, $resolver->requests);
        self::assertSame(
            "<p>@import &quot;snippet.md&quot;</p>\n",
            Markdown::commonmark()->toHtml("@import \"snippet.md\"\n"),
        );
    }

    public function testPropagatesTypedResolutionFailures(): void
    {
        $resolver = new class implements ResourceResolver {
            public function resolve(ResourceRequest $request): ResolvedResource
            {
                throw new ResourceDeniedException($request, 'fixture denial');
            }
        };

        $this->expectException(ResourceDeniedException::class);
        $this->expectExceptionMessage('fixture denial');

        Markdown::commonmark()
            ->with(new ImportExtension($resolver))
            ->toHtml('@import "denied.md"');
    }

    public function testHostileImportedHtmlIsAlwaysCodeAndSurvivesEveryHtmlPolicy(): void
    {
        $factory = Markdown::commonmark()->with(new ImportExtension(
            new ImportFixtureResolver(['hostile.html' => '<script>alert(1)</script>']),
        ));
        $source = '@import "hostile.html"';
        $expected = "<pre><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame(
            $expected,
            $factory->toHtml(
                $source,
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::spec()),
            ),
        );

        if (class_exists(\Dom\HTMLDocument::class)) {
            self::assertSame(
                $expected,
                $factory->toHtml(
                    $source,
                    renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
                ),
            );
        }
    }

    public function testEscapesBinaryContentWithoutEverCreatingAnHtmlTag(): void
    {
        $factory = Markdown::commonmark()->with(new ImportExtension(
            new ImportFixtureResolver(['binary.dat' => "<tag>\x00\xC3("]),
        ));

        $html = $factory->toHtml('@import "binary.dat"');

        self::assertStringStartsWith('<pre><code>&lt;tag&gt;', $html);
        self::assertStringNotContainsString('<tag>', $html);
        self::assertStringEndsWith("</code></pre>\n", $html);
    }

    public function testIndentsEmptyAndCrLfResourcesDeterministically(): void
    {
        $factory = Markdown::commonmark()->with(new ImportExtension(
            new ImportFixtureResolver([
                'empty.txt' => '',
                'windows.txt' => "first\r\nsecond\r\n",
            ]),
        ));

        self::assertSame(
            "<pre><code></code></pre>\n",
            $factory->toHtml('@import "empty.txt" {indent: 2}'),
        );
        self::assertSame(
            "<pre><code>  first\r\n  second\r\n</code></pre>\n",
            $factory->toHtml('@import "windows.txt" {indent: 2}'),
        );
    }
}

final class ImportFixtureResolver implements ResourceResolver
{
    /**
     * @var list<ResourceRequest>
     */
    public array $requests = [];

    /**
     * @param array<string, string> $resources
     */
    public function __construct(private array $resources)
    {
    }

    public function resolve(ResourceRequest $request): ResolvedResource
    {
        $this->requests[] = $request;

        return new ResolvedResource(
            id: 'fixture:'.$request->reference,
            bytes: $this->resources[$request->reference] ?? '',
        );
    }
}
