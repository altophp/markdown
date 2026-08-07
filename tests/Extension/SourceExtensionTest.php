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
use Alto\Markdown\Extension\Source\SourceExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Resource\ResolvedResource;
use Alto\Markdown\Resource\ResourceRequest;
use Alto\Markdown\Resource\ResourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceExtensionTest extends TestCase
{
    public function testRendersVisibleSourceMetadataOnceAndPreservesTheDirective(): void
    {
        $resolver = new SourceFixtureResolver([
            'src/App.php' => "one\n<two>\n",
        ]);
        $factory = Markdown::commonmark()->with(new SourceExtension($resolver));
        $source = "@source \"src/App.php\"\n";
        $expected = "<div class=\"source-block\">\n"
            ."<div class=\"source-path\">src/App.php</div>\n"
            ."<pre><code class=\"language-php\">one\n&lt;two&gt;\n</code></pre>\n"
            ."</div>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertCount(1, $resolver->requests);

        $document = $factory->fromString($source);
        self::assertCount(2, $resolver->requests);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($expected, $document->toHtml());
        self::assertCount(2, $resolver->requests);
        self::assertSame($source, $document->toMarkdown());
        self::assertSame($source, $document->toMarkdown(new RenderOptions()));
        self::assertCount(1, $document->query()->kind('source:block')->get()->all());

        self::assertSame('src/App.php', $resolver->requests[0]->reference);
        self::assertSame('source', $resolver->requests[0]->purpose);
        self::assertNull($resolver->requests[0]->originId);
    }

    public function testSelectsOriginalLinesAndRendersNormalizedHighlights(): void
    {
        $content = implode("\r\n", array_map(
            static fn (int $line): string => 'line '.$line,
            range(1, 12),
        ));
        $factory = Markdown::commonmark()->with(new SourceExtension(
            new SourceFixtureResolver(['src/example.php' => $content]),
        ));
        $source = '@source "src/example.php" {lines: 9-11, title: "A, \\"B\\" \\\\ path", '
            .'numbers: true, highlight: "11, 10, 10-11"}';

        self::assertSame(
            "<div class=\"source-block\">\n"
            ."<div class=\"source-title\">A, &quot;B&quot; \\ path</div>\n"
            ."<div class=\"source-path\">src/example.php</div>\n"
            .'<pre><code class="language-php">'
            .'<span class="line"><span class="line-number" data-line="9" aria-hidden="true">9</span>line 9</span>'
            ."\r\n"
            .'<span class="line highlighted"><span class="line-number" data-line="10" aria-hidden="true">10</span>line 10</span>'
            ."\r\n"
            .'<span class="line highlighted"><span class="line-number" data-line="11" aria-hidden="true">11</span>line 11</span>'
            ."</code></pre>\n"
            ."</div>\n",
            $factory->toHtml($source),
        );
    }

    public function testWrapsEveryLineWhenOnlyHighlightsAreEnabled(): void
    {
        $factory = Markdown::commonmark()->with(new SourceExtension(
            new SourceFixtureResolver(['mixed.txt' => "one\rtwo\nthree\r\n"]),
        ));

        self::assertSame(
            "<div class=\"source-block\">\n"
            ."<div class=\"source-path\">mixed.txt</div>\n"
            .'<pre><code class="language-text">'
            ."<span class=\"line\">one</span>\r"
            ."<span class=\"line highlighted\">two</span>\n"
            ."<span class=\"line\">three</span>\r\n"
            ."</code></pre>\n"
            ."</div>\n",
            $factory->toHtml('@source "mixed.txt" {highlight: 2}'),
        );
    }

    public function testKeepsDisjointHighlightRangesIndependent(): void
    {
        $factory = Markdown::commonmark()->with(new SourceExtension(
            new SourceFixtureResolver(['lines.txt' => "one\ntwo\nthree"]),
        ));

        $html = $factory->toHtml('@source "lines.txt" {highlight: "1, 3"}');

        self::assertSame(2, substr_count($html, 'line highlighted'));
        self::assertStringContainsString('<span class="line">two</span>', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function detectedLanguages(): iterable
    {
        yield 'Dockerfile' => ['containers/Dockerfile', 'dockerfile'];
        yield 'Dockerfile variant' => ['Dockerfile.production', 'dockerfile'];
        yield 'Makefile' => ['build/Makefile', 'makefile'];
        yield 'Makefile variant' => ['Makefile.dist', 'makefile'];
        yield 'CMakeLists' => ['CMakeLists.txt', 'cmake'];
        yield 'htaccess' => ['public/.htaccess', 'apache'];
        yield 'uppercase extension' => ['src/APP.PHP', 'php'];
        yield 'JavaScript' => ['app.mjs', 'javascript'];
        yield 'shell' => ['script.zsh', 'bash'];
        yield 'reStructuredText' => ['guide.rst', 'restructuredtext'];
        yield 'SVG' => ['icon.svg', 'xml'];
        yield 'C++' => ['main.hpp', 'cpp'];
        yield 'PowerShell' => ['build.ps1', 'powershell'];
        yield 'diff' => ['change.patch', 'diff'];
    }

    #[DataProvider('detectedLanguages')]
    public function testDetectsCommonLanguages(string $path, string $language): void
    {
        $factory = Markdown::commonmark()->with(new SourceExtension(
            new SourceFixtureResolver([$path => 'code']),
        ));

        self::assertStringContainsString(
            '<code class="language-'.$language.'">code</code>',
            $factory->toHtml('@source "'.$path.'"'),
        );
    }

    public function testExplicitLanguageWinsAndUnknownFilesHaveNoLanguageClass(): void
    {
        $resolver = new SourceFixtureResolver([
            'file.php' => 'one',
            'LICENSE' => 'two',
            'file.' => 'three',
        ]);
        $factory = Markdown::commonmark()->with(new SourceExtension($resolver));

        self::assertStringContainsString(
            '<code class="language-custom.lang">one</code>',
            $factory->toHtml('@source "file.php" {lang: custom.lang}'),
        );
        self::assertStringContainsString(
            '<pre><code>two</code></pre>',
            $factory->toHtml('@source "LICENSE"'),
        );
        self::assertStringContainsString(
            '<pre><code>three</code></pre>',
            $factory->toHtml('@source "file."'),
        );
    }

    public function testSelectsASmallDecoratedRangeFromAMediumResource(): void
    {
        $line = '0123456789abcdefgh';
        $resource = str_repeat($line."\r\n", 5_000)
            ."target one\r\ntarget two\r\ntarget three\r\n"
            .str_repeat($line."\r\n", 5_000);
        $factory = Markdown::commonmark()->with(new SourceExtension(
            new SourceFixtureResolver(['medium.txt' => $resource]),
        ));

        $html = $factory->toHtml(
            '@source "medium.txt" {lines: 5001-5003, numbers: true, highlight: 5002}',
        );

        self::assertGreaterThan(200_000, \strlen($resource));
        self::assertStringContainsString('data-line="5001"', $html);
        self::assertStringContainsString('data-line="5003"', $html);
        self::assertSame(1, substr_count($html, 'line highlighted'));
        self::assertStringNotContainsString($line, $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDirectives(): iterable
    {
        yield 'unquoted path' => ['@source snippet.php'];
        yield 'leading space' => [' @source "snippet.php"'];
        yield 'nested' => ['> @source "snippet.php"'];
        yield 'missing path' => ['@source'];
        yield 'similar name' => ['@sourcecode "snippet.php"'];
        yield 'unknown option' => ['@source "snippet.php" {unknown: true}'];
        yield 'duplicate option' => ['@source "snippet.php" {numbers: true, numbers: false}'];
        yield 'missing option value' => ['@source "snippet.php" {lang:}'];
        yield 'trailing comma' => ['@source "snippet.php" {lang: php,}'];
        yield 'unquoted title' => ['@source "snippet.php" {title: hello}'];
        yield 'empty title' => ['@source "snippet.php" {title: ""}'];
        yield 'invalid title escape' => ['@source "snippet.php" {title: "bad\\n"}'];
        yield 'unterminated title' => ['@source "snippet.php" {title: "bad}'];
        yield 'trailing title bytes' => ['@source "snippet.php" {title: "valid" junk}'];
        yield 'control title' => ["@source \"snippet.php\" {title: \"bad\nvalue\"}"];
        yield 'long title' => ['@source "snippet.php" {title: "'.str_repeat('x', 257).'"}'];
        yield 'quoted numbers' => ['@source "snippet.php" {numbers: "true"}'];
        yield 'invalid numbers' => ['@source "snippet.php" {numbers: yes}'];
        yield 'zero line' => ['@source "snippet.php" {lines: 0}'];
        yield 'quoted line' => ['@source "snippet.php" {lines: "2"}'];
        yield 'descending line range' => ['@source "snippet.php" {lines: 3-2}'];
        yield 'line above bound' => ['@source "snippet.php" {lines: 1000001}'];
        yield 'zero highlight' => ['@source "snippet.php" {highlight: 0}'];
        yield 'descending highlight' => ['@source "snippet.php" {highlight: 3-2}'];
        yield 'too many highlights' => [
            '@source "snippet.php" {highlight: "'.implode(',', range(1, 65)).'"}',
        ];
        yield 'too many duplicate highlights' => [
            '@source "snippet.php" {highlight: "'.implode(',', array_fill(0, 65, '1')).'"}',
        ];
        yield 'long highlight value' => [
            '@source "snippet.php" {highlight: "'.str_repeat('1,', 256).'1"}',
        ];
        yield 'invalid language' => ['@source "snippet.php" {lang: php html}'];
        yield 'long language' => ['@source "snippet.php" {lang: '.str_repeat('x', 65).'}'];
        yield 'long directive' => ['@source "'.str_repeat('x', 4_096).'"'];
    }

    #[DataProvider('invalidDirectives')]
    public function testInvalidDirectivesRemainLiteralWithoutIo(string $source): void
    {
        $resolver = new SourceFixtureResolver(['snippet.php' => 'secret']);
        $html = Markdown::commonmark()->with(new SourceExtension($resolver))->toHtml($source);

        self::assertCount(0, $resolver->requests);
        self::assertStringNotContainsString('<div class="source-block">', $html);
        self::assertStringNotContainsString('secret', $html);
    }

    public function testDoesNotInterruptAParagraphOrActivateWithoutTheExtension(): void
    {
        $resolver = new SourceFixtureResolver(['snippet.php' => 'secret']);
        $source = "Paragraph\n@source \"snippet.php\"\n";

        self::assertSame(
            "<p>Paragraph\n@source &quot;snippet.php&quot;</p>\n",
            Markdown::commonmark()->with(new SourceExtension($resolver))->toHtml($source),
        );
        self::assertCount(0, $resolver->requests);
        self::assertSame(
            "<p>@source &quot;snippet.php&quot;</p>\n",
            Markdown::commonmark()->toHtml("@source \"snippet.php\"\n"),
        );
    }

    public function testPropagatesTypedResolutionFailures(): void
    {
        $resolver = new class implements ResourceResolver {
            public function resolve(ResourceRequest $request): ResolvedResource
            {
                throw new ResourceDeniedException($request, 'source denied');
            }
        };

        $this->expectException(ResourceDeniedException::class);
        $this->expectExceptionMessage('source denied');

        Markdown::commonmark()
            ->with(new SourceExtension($resolver))
            ->toHtml('@source "denied.php"');
    }

    public function testHostileContentAndMetadataAreAlwaysEscaped(): void
    {
        $factory = Markdown::commonmark()->with(new SourceExtension(
            new SourceFixtureResolver([
                'hostile.html' => "<script>alert(1)</script>\x00\xC3(",
            ]),
        ));
        $source = '@source "hostile.html" {title: "<img src=x>"}';
        $html = $factory->toHtml($source);

        self::assertStringContainsString('&lt;img src=x&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);

        if (class_exists(\Dom\HTMLDocument::class)) {
            $curated = $factory->toHtml(
                $source,
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
            );
            $document = \Dom\HTMLDocument::createFromString($curated);

            self::assertNull($document->querySelector('script'));
            self::assertNull($document->querySelector('img'));
        }
    }
}

final class SourceFixtureResolver implements ResourceResolver
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
