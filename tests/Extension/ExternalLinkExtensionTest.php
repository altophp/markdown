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
use Alto\Markdown\Extension\ExternalLink\ExternalLinkDecorator;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkExtension;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkPolicy;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkScope;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;
use Alto\Markdown\Extension\Mention\MentionDefinition;
use Alto\Markdown\Extension\Mention\MentionExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlPolicy;
use PHPUnit\Framework\TestCase;

final class ExternalLinkExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testDecoratesExternalLinksAndAutolinksInEveryHtmlLane(): void
    {
        $factory = Markdown::commonmark()->with(new ExternalLinkExtension(
            new ExternalLinkPolicy(internalHosts: ['internal.test']),
        ));
        $source = 'See [outside](https://outside.test/docs), '
            ."[inside](https://internal.test/docs), and <https://outside.test/help>.\n";
        $expectedInline = 'See <a rel="noopener noreferrer" href="https://outside.test/docs">outside</a>, '
            .'<a href="https://internal.test/docs">inside</a>, and '
            .'<a rel="noopener noreferrer" href="https://outside.test/help">https://outside.test/help</a>.';

        self::assertSame('<p>'.$expectedInline."</p>\n", $factory->toHtml($source));
        self::assertSame('<p>'.$expectedInline."</p>\n", $factory->fromString($source)->toHtml());
        self::assertSame($expectedInline, $factory->toInlineHtml(rtrim($source)));
        self::assertSame($source, $factory->fromString($source)->toMarkdown());
    }

    public function testConfiguresClassTargetAndRelScopes(): void
    {
        $factory = Markdown::commonmark()->with(new ExternalLinkExtension(
            new ExternalLinkPolicy(
                internalHosts: ['internal.test'],
                openInNewWindow: true,
                htmlClass: 'external',
                nofollow: ExternalLinkScope::External,
                noopener: ExternalLinkScope::All,
                noreferrer: ExternalLinkScope::Internal,
            ),
        ));

        self::assertSame(
            '<p><a class="external" rel="nofollow noopener" target="_blank" href="https://outside.test">outside</a> '
            .'<a rel="noopener noreferrer" href="https://internal.test">inside</a></p>'."\n",
            $factory->toHtml("[outside](https://outside.test) [inside](https://internal.test)\n"),
        );
    }

    public function testDecoratesMentionLinksThroughTheirDeclaredSemantics(): void
    {
        $factory = Markdown::commonmark()->with(
            new MentionExtension(MentionDefinition::links(
                'user',
                '@',
                '[a-z]+',
                'https://profiles.test/%s',
            )),
            new ExternalLinkExtension(),
        );
        $source = "Hello @ada.\n";
        $expected = "<p>Hello <a rel=\"noopener noreferrer\" href=\"https://profiles.test/ada\">@ada</a>.</p>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());
        self::assertSame(
            'Hello <a rel="noopener noreferrer" href="https://profiles.test/ada">@ada</a>.',
            $factory->toInlineHtml(rtrim($source)),
        );
    }

    public function testActiveExtensionHasNoDispatchCostUntilALinkIsFound(): void
    {
        $factory = Markdown::commonmark()->with(new ExternalLinkExtension());

        Instrumentation::reset();
        self::assertSame("<p>Plain text.</p>\n", $factory->toHtml("Plain text.\n"));
        self::assertSame(0, Instrumentation::$htmlDecoratorInvocations);
        self::assertSame(0, Instrumentation::$fusedInlineFallbacks);

        Instrumentation::reset();
        self::assertSame(
            "<p><a rel=\"noopener noreferrer\" href=\"https://outside.test\">https://outside.test</a></p>\n",
            $factory->toHtml("<https://outside.test>\n"),
        );
        self::assertSame(1, Instrumentation::$htmlDecoratorInvocations);
        self::assertSame(0, Instrumentation::$fusedInlineFallbacks);

        Instrumentation::reset();
        self::assertSame(
            "<p><a rel=\"noopener noreferrer\" href=\"https://outside.test\">outside</a></p>\n",
            $factory->toHtml("[outside](https://outside.test)\n"),
        );
        self::assertSame(1, Instrumentation::$htmlDecoratorInvocations);
        self::assertSame(1, Instrumentation::$fusedInlineFallbacks);
        self::assertSame(1, Instrumentation::$fusedFallbackReasons['link-decoration'] ?? 0);
    }

    public function testSubdomainMatchingIsExplicit(): void
    {
        $exact = Markdown::commonmark()->with(new ExternalLinkExtension(
            new ExternalLinkPolicy(internalHosts: ['example.com']),
        ));
        $subdomains = Markdown::commonmark()->with(new ExternalLinkExtension(
            new ExternalLinkPolicy(internalHosts: ['Example.COM.'], includeSubdomains: true),
        ));
        $source = '[docs](https://docs.example.com)';

        self::assertSame(
            '<a rel="noopener noreferrer" href="https://docs.example.com">docs</a>',
            $exact->toInlineHtml($source),
        );
        self::assertSame(
            '<a href="https://docs.example.com">docs</a>',
            $subdomains->toInlineHtml($source),
        );
    }

    public function testRelativeFragmentsEmailAndUnsafeSchemesAreNotClassifiedAsExternalHosts(): void
    {
        $factory = Markdown::commonmark()->with(new ExternalLinkExtension());

        self::assertSame(
            '<a href="/local">local</a> <a href="#part">part</a> '
            .'<a href="mailto:user@example.com">mail</a> <a href="">unsafe</a>',
            $factory->toInlineHtml(
                '[local](/local) [part](#part) [mail](mailto:user@example.com) [unsafe](javascript:alert(1))',
            ),
        );
    }

    public function testEveryExternalAttributeCanBeDisabled(): void
    {
        $factory = Markdown::commonmark()->with(new ExternalLinkExtension(
            new ExternalLinkPolicy(
                noopener: ExternalLinkScope::None,
                noreferrer: ExternalLinkScope::None,
            ),
        ));

        self::assertSame(
            '<a href="https://outside.test">outside</a>',
            $factory->toInlineHtml('[outside](https://outside.test)'),
        );
    }

    public function testClassIsEscapedThroughTheOutputContext(): void
    {
        $factory = Markdown::commonmark()->with(new ExternalLinkExtension(
            new ExternalLinkPolicy(htmlClass: 'external" data-test="safe'),
        ));

        self::assertSame(
            '<a class="external&quot; data-test=&quot;safe" rel="noopener noreferrer" href="https://outside.test">outside</a>',
            $factory->toInlineHtml('[outside](https://outside.test)'),
        );
    }

    public function testDecoratorPreservesEarlierWrappersAndMissingAnchors(): void
    {
        $decorator = new ExternalLinkDecorator(new ExternalLinkPolicy());
        $context = new HtmlNodeOutputContext(
            'link',
            null,
            '',
            HtmlPolicy::safe(),
            ['destination' => 'https://outside.test'],
        );

        self::assertSame(
            '<span><a rel="noopener noreferrer" href="https://outside.test">outside</a></span>',
            $decorator->decorate($context, '<span><a href="https://outside.test">outside</a></span>'),
        );
        self::assertSame(
            '<A rel="noopener noreferrer">outside</A>',
            $decorator->decorate($context, '<A>outside</A>'),
        );
        self::assertSame('<span>outside</span>', $decorator->decorate($context, '<span>outside</span>'));
    }

    public function testPolicyNormalizesAndDeduplicatesHosts(): void
    {
        $policy = new ExternalLinkPolicy(
            internalHosts: ['Example.COM.', 'example.com', '127.0.0.1', '::1'],
            includeSubdomains: true,
        );

        self::assertSame(['example.com', '127.0.0.1', '::1'], $policy->internalHosts);
        self::assertTrue($policy->isInternalHost('API.EXAMPLE.COM.'));
        self::assertTrue($policy->isInternalHost('127.0.0.1'));
        self::assertTrue($policy->isInternalHost('[::1]'));
        self::assertFalse($policy->isInternalHost('notexample.com'));
    }

    public function testRelScopesHaveExplicitTruthTables(): void
    {
        self::assertFalse(ExternalLinkScope::None->applies(false));
        self::assertFalse(ExternalLinkScope::None->applies(true));
        self::assertTrue(ExternalLinkScope::All->applies(false));
        self::assertTrue(ExternalLinkScope::All->applies(true));
        self::assertTrue(ExternalLinkScope::Internal->applies(false));
        self::assertFalse(ExternalLinkScope::Internal->applies(true));
        self::assertFalse(ExternalLinkScope::External->applies(false));
        self::assertTrue(ExternalLinkScope::External->applies(true));
    }

    public function testInvalidInternalHostsAreRejected(): void
    {
        $invalid = [
            [''],
            ['https://example.com'],
            ['bad host'],
            ['-example.com'],
        ];

        foreach ($invalid as $hosts) {
            try {
                new ExternalLinkPolicy(internalHosts: $hosts);
                self::fail('Expected the invalid internal host to be rejected.');
            } catch (InvalidMarkdownArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('internal hosts must be strings');

        new \ReflectionClass(ExternalLinkPolicy::class)->newInstance([3]);
    }
}
