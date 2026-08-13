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

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\InvalidMarkdownOperationException;
use Alto\Markdown\Exception\UnsupportedDocumentModelException;
use Alto\Markdown\Extension\DefaultAttributes\DefaultAttributesExtension;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkExtension;
use Alto\Markdown\Extension\ExternalLink\ExternalLinkPolicy;
use Alto\Markdown\Extension\LinkRewrite\LinkDestinationContext;
use Alto\Markdown\Extension\LinkRewrite\LinkRewriter;
use Alto\Markdown\Extension\LinkRewrite\LinkRewriterExtension;
use Alto\Markdown\Extension\Mention\MentionDefinition;
use Alto\Markdown\Extension\Mention\MentionExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseOptions;
use PHPUnit\Framework\TestCase;

final class LinkRewriterExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testRenderOnlyExtensionRewritesLinksImagesAndAutolinksInBothLanes(): void
    {
        $rewriter = LinkRewriter::map([
            '/guide' => '/v2/guide',
            '/logo.png' => 'https://cdn.example/logo.png',
            '/entity.png' => 'https://cdn.example/entity.png',
            'https://old.example/path' => 'https://new.example/path',
        ]);
        $factory = Markdown::commonmark()->with(new LinkRewriterExtension($rewriter));
        $source = "[Guide](/guide) ![Logo](/logo.png) ![A &amp; B](/entity.png) <https://old.example/path>\n";
        $expected = '<p><a href="/v2/guide">Guide</a> '
            . '<img src="https://cdn.example/logo.png" alt="Logo" /> '
            . '<img src="https://cdn.example/entity.png" alt="A &amp; B" /> '
            . '<a href="https://new.example/path">https://old.example/path</a></p>' . "\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame(
            $expected,
            $factory->toHtml($source, new ParseOptions(maxInlineCount: 100)),
        );
        self::assertSame(
            '<a href="/v2/guide">Guide</a>',
            $factory->toInlineHtml('[Guide](/guide)'),
        );
        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
    }

    public function testInactiveProfilesAndUnmatchedDocumentsDispatchNoRewrite(): void
    {
        Instrumentation::reset();
        Markdown::commonmark()->toHtml("[Link](/guide)\n");
        self::assertSame(0, Instrumentation::$linkDestinationRewrites);

        $factory = Markdown::commonmark()->with(new LinkRewriterExtension(
            LinkRewriter::map(['/guide' => '/new']),
        ));
        Instrumentation::reset();
        self::assertSame("<p>Plain.</p>\n", $factory->toHtml("Plain.\n"));
        self::assertSame(0, Instrumentation::$linkDestinationRewrites);

        Instrumentation::reset();
        self::assertSame("<p><a href=\"/other\">Other</a></p>\n", $factory->toHtml("[Other](/other)\n"));
        self::assertSame(1, Instrumentation::$linkDestinationRewrites);
    }

    public function testBaseUriHasDeliberatePrefixSemantics(): void
    {
        $factory = Markdown::commonmark()->with(new LinkRewriterExtension(
            LinkRewriter::baseUri('https://docs.example/base/'),
        ));
        $source = '[Root](/guide) [Path](guide) [Empty]() [Fragment](#part) '
            . "[Query](?page=2) [Network](//cdn.example/a) [Absolute](mailto:a@example.com)\n";
        $html = $factory->toHtml($source);

        self::assertStringContainsString('href="https://docs.example/base/guide"', $html);
        self::assertSame(2, substr_count($html, 'href="https://docs.example/base/guide"'));
        self::assertStringContainsString('href=""', $html);
        self::assertStringContainsString('href="#part"', $html);
        self::assertStringContainsString('href="?page=2"', $html);
        self::assertStringContainsString('href="//cdn.example/a"', $html);
        self::assertStringContainsString('href="mailto:a@example.com"', $html);
    }

    public function testTableCellLinksRewriteWithoutClaimingOriginalCellRanges(): void
    {
        $factory = Markdown::gfm()->with(new LinkRewriterExtension(
            LinkRewriter::map(['/guide' => '/v2/guide']),
        ));
        $source = "| Link |\n| --- |\n| [Guide](/guide) |\n";
        $expected = "<table>\n<thead>\n<tr>\n<th>Link</th>\n</tr>\n</thead>\n"
            . "<tbody>\n<tr>\n<td><a href=\"/v2/guide\">Guide</a></td>\n"
            . "</tr>\n</tbody>\n</table>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());
    }

    public function testStrategiesComposeInDeclaredOrderAndExposeImmutableContext(): void
    {
        $seen = [];
        $rewriter = LinkRewriter::baseUri('https://docs.example')->then(
            LinkRewriter::map(['https://docs.example/old' => 'https://docs.example/new']),
        );
        $rewriter = LinkRewriter::compose(
            $rewriter,
            LinkRewriter::pattern('~/new$~', '/current'),
            LinkRewriter::callback(static function (LinkDestinationContext $context) use (&$seen): string {
                $seen[] = [$context->kind, $context->destination, $context->source()];

                return $context->destination . '?from=markdown';
            }),
        );
        $factory = Markdown::commonmark()->with(new LinkRewriterExtension($rewriter));

        self::assertSame(
            "<p><a href=\"https://docs.example/current?from=markdown\">Guide</a></p>\n",
            $factory->toHtml("[Guide](/old)\n"),
        );
        self::assertSame([['link', 'https://docs.example/current', '[Guide](/old)']], $seen);
    }

    public function testEncodedDestinationContractMatchesDirectDocumentAndMutationLanes(): void
    {
        $seen = [];
        $rewriter = LinkRewriter::callback(
            static function (LinkDestinationContext $context) use (&$seen): string {
                $seen[] = $context->destination;

                return '/new path%20?x=&';
            },
        );
        $source = "[x](<a b\\*c&amp;%20>)\n";
        $factory = Markdown::commonmark()->with(new LinkRewriterExtension($rewriter));
        $expected = "<p><a href=\"/new%20path%20?x=&amp;\">x</a></p>\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());

        $document = Markdown::commonmark()->fromString($source);
        $result = $rewriter->rewriteDocument($document);

        self::assertSame(1, $result->rewritten);
        self::assertTrue($result->hasChanges());
        self::assertSame("[x](/new%20path%20?x=&)\n", $document->toMarkdown());
        self::assertSame(
            ['a%20b*c&%20', 'a%20b*c&%20', 'a%20b*c&%20'],
            $seen,
        );
    }

    public function testCustomLinkSemanticsReceiveTheRewrittenDestination(): void
    {
        $factory = Markdown::commonmark()->with(
            new MentionExtension(MentionDefinition::links(
                'user',
                '@',
                '[a-z]+',
                'https://users.example/team docs/%s',
            )),
            new LinkRewriterExtension(LinkRewriter::map([
                'https://users.example/team%20docs/alice' => '/people/alice',
            ])),
        );

        self::assertSame(
            "<p>Hello <a href=\"/people/alice\">@alice</a>.</p>\n",
            $factory->toHtml("Hello @alice.\n"),
        );
        self::assertSame(
            "<p>Hello <a href=\"/people/alice\">@alice</a>.</p>\n",
            $factory->fromString("Hello @alice.\n")->toHtml(),
        );
    }

    public function testExternalLinkAndDefaultAttributesSeeTheFinalDestination(): void
    {
        $factory = Markdown::commonmark()->with(
            new LinkRewriterExtension(LinkRewriter::map([
                'https://internal.example/guide' => 'https://outside.example/guide',
            ])),
            new ExternalLinkExtension(new ExternalLinkPolicy(
                internalHosts: ['internal.example'],
                openInNewWindow: true,
                htmlClass: 'external',
            )),
            new DefaultAttributesExtension([
                'link' => ['href' => '/default', 'data-link' => true],
            ]),
        );

        $source = "[Guide](https://internal.example/guide)\n";
        $expected = '<p><a class="external" rel="noopener noreferrer" target="_blank" '
            . 'href="https://outside.example/guide" data-link>Guide</a></p>' . "\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());
    }

    public function testUrlPolicyRunsAfterRewriting(): void
    {
        $factory = Markdown::commonmark()->with(new LinkRewriterExtension(
            LinkRewriter::map([
                '/safe' => 'javascript:alert(1)',
                '/image.png' => 'data:text/html,bad',
            ]),
        ));

        self::assertSame(
            "<p><a href=\"\">Link</a> <img src=\"\" alt=\"Image\" /></p>\n",
            $factory->toHtml("[Link](/safe) ![Image](/image.png)\n"),
        );
    }

    public function testExplicitMutationPlansMultipleMinimalEditsBeforeApplyingThem(): void
    {
        $rewriter = LinkRewriter::baseUri('https://docs.example');
        $document = Markdown::commonmark()->with(new LinkRewriterExtension($rewriter))->fromString(
            "[One](/one) and [Two](/two) with ![Logo](/logo.png) and [Mail](mailto:a@example.com).\n",
        );

        $result = $rewriter->rewriteDocument($document);

        self::assertSame(3, $result->rewritten);
        self::assertSame(1, $result->unchanged);
        self::assertSame(0, $result->skippedReferences);
        self::assertSame(0, $result->skippedOverlaps);
        self::assertSame(
            '[One](https://docs.example/one) and [Two](https://docs.example/two) '
            . "with ![Logo](https://docs.example/logo.png) and [Mail](mailto:a@example.com).\n",
            $document->toMarkdown(),
        );
        self::assertCount(3, $document->model()->journal()->operations());
        self::assertCount(3, $document->model()->journal()->entries());
        self::assertSame(
            '<p><a href="https://docs.example/one">One</a> and '
            . '<a href="https://docs.example/two">Two</a> with '
            . '<img src="https://docs.example/logo.png" alt="Logo" /> and '
            . '<a href="mailto:a@example.com">Mail</a>.</p>' . "\n",
            $document->toHtml(),
        );
    }

    public function testExplicitMutationSkipsReferencesAndOverlappingDestinations(): void
    {
        $source = "[Reference][guide] ![Asset][image]\n\n"
            . "[![Nested](/nested.png)](/outer)\n\n"
            . "[guide]: /guide\n"
            . "[image]: /image.png\n";
        $document = Markdown::commonmark()->fromString($source);

        $result = LinkRewriter::baseUri('https://docs.example')->rewriteDocument($document);

        self::assertSame(0, $result->rewritten);
        self::assertSame(2, $result->skippedReferences);
        self::assertSame(2, $result->skippedOverlaps);
        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
        self::assertFalse($result->hasChanges());
    }

    public function testReferenceNestedInsideInlineLinkDoesNotHideTheOuterRewrite(): void
    {
        $source = "[![Nested][asset]](/outer)\n\n[asset]: /asset.png\n";
        $document = Markdown::commonmark()->fromString($source);

        $result = LinkRewriter::baseUri('https://docs.example')->rewriteDocument($document);

        self::assertSame(1, $result->rewritten);
        self::assertSame(1, $result->skippedReferences);
        self::assertSame(0, $result->skippedOverlaps);
        self::assertSame(
            "[![Nested][asset]](https://docs.example/outer)\n\n[asset]: /asset.png\n",
            $document->toMarkdown(),
        );
    }

    public function testReferenceWrapperDoesNotHideItsNestedInlineImageRewrite(): void
    {
        $source = "[![Nested](/asset.png)][outer]\n\n[outer]: /outer\n";
        $document = Markdown::commonmark()->fromString($source);

        $result = LinkRewriter::baseUri('https://docs.example')->rewriteDocument($document);

        self::assertSame(1, $result->rewritten);
        self::assertSame(1, $result->skippedReferences);
        self::assertSame(0, $result->skippedOverlaps);
        self::assertSame(
            "[![Nested](https://docs.example/asset.png)][outer]\n\n[outer]: /outer\n",
            $document->toMarkdown(),
        );
    }

    public function testLatePlanningFailureLeavesTheDocumentUntouched(): void
    {
        $calls = 0;
        $rewriter = LinkRewriter::callback(
            static function (LinkDestinationContext $context) use (&$calls): string {
                if (2 === ++$calls) {
                    throw new \RuntimeException('late failure');
                }

                return '/rewritten' . $context->destination;
            },
        );
        $source = "[One](/one) [Two](/two)\n";
        $document = Markdown::commonmark()->fromString($source);

        try {
            $rewriter->rewriteDocument($document);
            self::fail('The second planned rewrite must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('late failure', $error->getMessage());
        }

        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
    }

    public function testMutationRejectsDocumentsWithPendingEdits(): void
    {
        $document = Markdown::commonmark()->fromString("[One](/one)\n");
        $link = $document->links()->first();
        self::assertNotNull($link);
        $link->setDestination('/edited');

        $this->expectException(InvalidMarkdownOperationException::class);
        $this->expectExceptionMessage('before making other document edits');

        LinkRewriter::baseUri('https://docs.example')->rewriteDocument($document);
    }

    public function testRejectsInvalidBaseMapsPatternsAndCallbackOutputs(): void
    {
        foreach ([
            static fn(): LinkRewriter => LinkRewriter::baseUri(''),
            static fn(): mixed => new \ReflectionMethod(LinkRewriter::class, 'map')
                ->invoke(null, [1 => '/invalid-key']),
            static fn(): LinkRewriter => LinkRewriter::map(['/ok' => "bad\x00"]),
            static fn(): LinkRewriter => LinkRewriter::pattern('/[/', 'x'),
        ] as $factory) {
            try {
                $factory();
                self::fail('Invalid rewriter configuration must fail.');
            } catch (InvalidMarkdownArgumentException) {
            }
        }

        $factory = Markdown::commonmark()->with(new LinkRewriterExtension(
            LinkRewriter::callback(static fn(): string => "bad\x00"),
        ));

        $this->expectException(InvalidMarkdownArgumentException::class);
        $factory->toHtml("[Link](/safe)\n");
    }

    public function testPatternRuntimeErrorsAreExplicit(): void
    {
        $rewriter = LinkRewriter::pattern('/./u', 'x');

        $this->expectException(InvalidMarkdownOperationException::class);
        $this->expectExceptionMessage('Malformed UTF-8');

        $rewriter->rewrite(new LinkDestinationContext('link', "\xFF", null, ''));
    }

    public function testMutationRejectsAnotherDocumentModelImplementation(): void
    {
        $document = self::createStub(MarkdownDocument::class);
        $document->method('model')->willReturn(self::createStub(DocumentModel::class));

        $this->expectException(UnsupportedDocumentModelException::class);
        $this->expectExceptionMessage('requires ParsedDocumentModel');

        LinkRewriter::baseUri('https://docs.example')->rewriteDocument($document);
    }
}
