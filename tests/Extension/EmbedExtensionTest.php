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
use Alto\Markdown\Exception\ResourceTooLargeException;
use Alto\Markdown\Extension\Embed\EmbedExtension;
use Alto\Markdown\Extension\Embed\EmbedFallback;
use Alto\Markdown\Extension\Embed\EmbedPolicy;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\HtmlSanitizer;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Resource\ResolvedResource;
use Alto\Markdown\Resource\ResourceRequest;
use Alto\Markdown\Resource\ResourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmbedExtensionTest extends TestCase
{
    public function testResolvesOncePerParseAndKeepsRawHtmlBehindTheActivePolicy(): void
    {
        $url = 'https://video.example/watch?v=1';
        $resolver = new EmbedFixtureResolver([
            $url => '<iframe src="https://video.example/embed/1"></iframe>',
        ]);
        $factory = Markdown::commonmark()->with(new EmbedExtension(
            $resolver,
            new EmbedPolicy(['video.example']),
        ));
        $source = "Before.\n\n".$url."  \n\nAfter.\n";
        $safe = "<p>Before.</p>\n"
            .'<p><a href="'.$url.'">'.$url."</a></p>\n"
            ."<p>After.</p>\n";
        $raw = "<p>Before.</p>\n"
            ."<iframe src=\"https://video.example/embed/1\"></iframe>\n"
            ."<p>After.</p>\n";

        $document = $factory->fromString($source);

        self::assertCount(1, $resolver->requests);
        self::assertSame('embed', $resolver->requests[0]->purpose);
        self::assertSame($url, $resolver->requests[0]->reference);
        self::assertSame($safe, $document->toHtml());
        self::assertSame(
            $raw,
            $document->toHtml(new RenderOptions(htmlPolicy: HtmlPolicy::spec())),
        );
        self::assertSame($safe, $document->toHtml());
        self::assertCount(1, $resolver->requests);
        self::assertSame($source, $document->toMarkdown());
        self::assertSame(
            "Before\\.\n\n".$url."  \n\nAfter\\.\n",
            $document->toMarkdown(new RenderOptions()),
        );
        self::assertCount(1, $document->query()->kind('embed:block')->get()->all());

        self::assertSame($safe, $factory->toHtml($source));
        self::assertCount(2, $resolver->requests);
    }

    public function testFinalSanitizerReceivesTheCompleteOutputOnce(): void
    {
        $url = 'https://video.example/watch';
        $resolver = new EmbedFixtureResolver([
            $url => '<iframe src="https://video.example/embed"></iframe>',
        ]);
        $sanitizer = new EmbedRecordingSanitizer();
        $policy = HtmlPolicy::safe()->withSanitizer($sanitizer);
        $factory = Markdown::commonmark()->with(new EmbedExtension(
            $resolver,
            new EmbedPolicy(['video.example']),
        ));

        $html = $factory->toHtml(
            "Before.\n\n".$url."\n\nAfter.\n",
            renderOptions: new RenderOptions(htmlPolicy: $policy),
        );

        self::assertCount(1, $sanitizer->inputs);
        self::assertSame($html, $sanitizer->inputs[0]);
        self::assertStringContainsString('<iframe', $html);
        self::assertStringContainsString('<p>Before.</p>', $html);
        self::assertStringContainsString('<p>After.</p>', $html);
    }

    public function testHostMatchingUsesExactDnsBoundaries(): void
    {
        $allowed = 'https://www.youtube.com/watch?v=1';
        $exact = 'HTTPS://YOUTUBE.COM.:443/watch?v=2';
        $resolver = new EmbedFixtureResolver([
            $allowed => '<div>subdomain</div>',
            $exact => '<div>exact</div>',
        ]);
        $factory = Markdown::commonmark()->with(new EmbedExtension(
            $resolver,
            new EmbedPolicy(['YouTube.COM.'], includeSubdomains: true),
        ));
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());

        self::assertSame("<div>subdomain</div>\n", $factory->toHtml($allowed, renderOptions: $options));
        self::assertSame("<div>exact</div>\n", $factory->toHtml($exact, renderOptions: $options));

        foreach ([
            'https://youtube.com.evil/watch',
            'https://notyoutube.com/watch',
            'https://youtube.com:8443/watch',
        ] as $url) {
            self::assertSame(
                '<p><a href="'.$url.'">'.$url."</a></p>\n",
                $factory->toHtml($url, renderOptions: $options),
            );
        }

        self::assertCount(2, $resolver->requests);
    }

    public function testHttpRequiresExplicitPermission(): void
    {
        $url = 'http://video.example/watch';
        $resolver = new EmbedFixtureResolver([$url => '<div>embed</div>']);
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());

        self::assertSame(
            '<p><a href="'.$url.'">'.$url."</a></p>\n",
            Markdown::commonmark()
                ->with(new EmbedExtension($resolver, new EmbedPolicy(['video.example'])))
                ->toHtml($url, renderOptions: $options),
        );
        self::assertCount(0, $resolver->requests);

        self::assertSame(
            "<div>embed</div>\n",
            Markdown::commonmark()
                ->with(new EmbedExtension(
                    $resolver,
                    new EmbedPolicy(['video.example'], allowHttp: true),
                ))
                ->toHtml($url, renderOptions: $options),
        );
        self::assertCount(1, $resolver->requests);
    }

    public function testRemoveFallbackPerformsNoLookupForDisallowedHosts(): void
    {
        $resolver = new EmbedFixtureResolver([]);
        $factory = Markdown::commonmark()->with(new EmbedExtension(
            $resolver,
            new EmbedPolicy(['allowed.example'], fallback: EmbedFallback::Remove),
        ));

        self::assertSame(
            "<p>Before.</p>\n<p>After.</p>\n",
            $factory->toHtml("Before.\n\nhttps://denied.example/watch\n\nAfter.\n"),
        );
        self::assertCount(0, $resolver->requests);
    }

    public function testRejectsOversizedResolvedHtml(): void
    {
        $url = 'https://video.example/watch';
        $resolver = new EmbedFixtureResolver([$url => '123456']);

        try {
            Markdown::commonmark()
                ->with(new EmbedExtension(
                    $resolver,
                    new EmbedPolicy(['video.example'], maxHtmlBytes: 5),
                ))
                ->toHtml($url);
            self::fail('Expected oversized embed HTML to be rejected.');
        } catch (ResourceTooLargeException $error) {
            self::assertSame(5, $error->maxBytes);
            self::assertSame(6, $error->actualBytes);
            self::assertSame('embed', $error->request->purpose);
            self::assertSame($url, $error->request->reference);
        }
    }

    public function testKeepsEmptyAndTerminatedResolvedHtmlUnchanged(): void
    {
        $empty = 'https://video.example/empty';
        $terminated = 'https://video.example/terminated';
        $factory = Markdown::commonmark()->with(new EmbedExtension(
            new EmbedFixtureResolver([
                $empty => '',
                $terminated => "<figure>Resolved</figure>\n",
            ]),
            new EmbedPolicy(['video.example']),
        ));
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());

        self::assertSame('', $factory->toHtml($empty, renderOptions: $options));
        self::assertSame(
            "<figure>Resolved</figure>\n",
            $factory->toHtml($terminated, renderOptions: $options),
        );
    }

    public function testPolicyCoversInvalidUrlsIpHostsAndUrlLimits(): void
    {
        $ipv6 = new EmbedPolicy(['::1']);

        self::assertTrue($ipv6->allowsUrl('https://[::1]/watch'));
        self::assertFalse($ipv6->allowsUrl('not a URL'));

        $default = new EmbedPolicy(['video.example']);

        self::assertFalse($default->isValidUrl('ftp://video.example/watch'));
        self::assertFalse($default->isValidUrl('https://-bad.example/watch'));

        $policy = new EmbedPolicy(['video.example'], maxUrlBytes: 12);

        self::assertSame(
            "<p>https://video.example/watch</p>\n",
            Markdown::commonmark()
                ->with(new EmbedExtension(new EmbedFixtureResolver([]), $policy))
                ->toHtml("https://video.example/watch\n"),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonEmbedSources(): iterable
    {
        yield 'paragraph continuation' => ["Paragraph\nhttps://video.example/watch\n"];
        yield 'leading space' => [" https://video.example/watch\n"];
        yield 'block quote' => ["> https://video.example/watch\n"];
        yield 'list item' => ["- https://video.example/watch\n"];
        yield 'fenced code' => ["```\nhttps://video.example/watch\n```\n"];
        yield 'userinfo' => ["https://user@video.example/watch\n"];
        yield 'missing host' => ["https:///watch\n"];
        yield 'trailing prose' => ["https://video.example/watch now\n"];
    }

    #[DataProvider('nonEmbedSources')]
    public function testOnlyRecognizesAValidRootUrlOnItsOwnLine(string $source): void
    {
        $resolver = new EmbedFixtureResolver([
            'https://video.example/watch' => '<div>embed</div>',
        ]);
        $html = Markdown::commonmark()
            ->with(new EmbedExtension($resolver, new EmbedPolicy(['video.example'])))
            ->toHtml($source);

        self::assertCount(0, $resolver->requests);
        self::assertStringNotContainsString('<div>embed</div>', $html);
    }

    public function testSyntaxIsInactiveWithoutTheExtension(): void
    {
        self::assertSame(
            "<p>https://video.example/watch</p>\n",
            Markdown::commonmark()->toHtml("https://video.example/watch\n"),
        );
    }

    public function testPolicyValidatesHostsAndLimits(): void
    {
        $invalid = [
            [],
            [''],
            ['https://example.com'],
            ['bad host'],
            ['-example.com'],
        ];

        foreach ($invalid as $hosts) {
            try {
                new EmbedPolicy($hosts);
                self::fail('Expected invalid embed hosts to be rejected.');
            } catch (InvalidMarkdownArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }

        foreach ([
            static fn (): EmbedPolicy => new EmbedPolicy(['example.com'], maxUrlBytes: 0),
            static fn (): EmbedPolicy => new EmbedPolicy(['example.com'], maxUrlBytes: \PHP_INT_MAX),
            static fn (): EmbedPolicy => new EmbedPolicy(['example.com'], maxHtmlBytes: 0),
            static fn (): EmbedPolicy => new EmbedPolicy(['example.com'], maxHtmlBytes: \PHP_INT_MAX),
        ] as $create) {
            try {
                $create();
                self::fail('Expected invalid embed limits to be rejected.');
            } catch (InvalidMarkdownArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('allowed hosts must be strings');

        new \ReflectionClass(EmbedPolicy::class)->newInstance([3]);
    }
}

final class EmbedFixtureResolver implements ResourceResolver
{
    /**
     * @var list<ResourceRequest>
     */
    public array $requests = [];

    /**
     * @param array<string, string> $html
     */
    public function __construct(private array $html)
    {
    }

    public function resolve(ResourceRequest $request): ResolvedResource
    {
        $this->requests[] = $request;

        return new ResolvedResource(
            'embed:'.hash('sha256', $request->reference),
            $this->html[$request->reference] ?? throw new \LogicException('Missing embed fixture.'),
        );
    }
}

final class EmbedRecordingSanitizer implements HtmlSanitizer
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
        return 'embed-recording';
    }
}
