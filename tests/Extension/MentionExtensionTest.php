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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Mention\Mention;
use Alto\Markdown\Extension\Mention\MentionDefinition;
use Alto\Markdown\Extension\Mention\MentionExtension;
use Alto\Markdown\Extension\Mention\MentionResolver;
use Alto\Markdown\Extension\Mention\MentionTarget;
use Alto\Markdown\Extension\Mention\UrlTemplateMentionResolver;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class MentionExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testMentionRendersThroughDirectAndDocumentLanesAndPreservesMarkdown(): void
    {
        $factory = $this->factory();
        $source = "Hello **@Ada-Lovelace**.\n";
        $expected = "<p>Hello <strong><a href=\"https://github.com/Ada-Lovelace\">@Ada-Lovelace</a></strong>.</p>\n";

        self::assertSame($expected, $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertSame("Hello **@Ada-Lovelace**\\.\n", $document->toMarkdown(new RenderOptions()));
    }

    public function testMultipleMentionTypesShareACompiledTrigger(): void
    {
        $factory = Markdown::commonmark()->with(new MentionExtension(
            MentionDefinition::links('user', '@', '[A-Z][A-Z0-9-]*(?![A-Z0-9-])', 'https://example.com/users/%s'),
            MentionDefinition::links('team', '@@', '[A-Z][A-Z0-9-]*(?![A-Z0-9-])', 'https://example.com/teams/%s'),
            MentionDefinition::links('issue', '#', '\d+(?!\d)', 'https://example.com/issues/%s'),
        ));

        self::assertSame(
            '<p><a href="https://example.com/teams/Core">@@Core</a> '
            . '<a href="https://example.com/users/Ada">@Ada</a> '
            . "<a href=\"https://example.com/issues/42\">#42</a></p>\n",
            $factory->toHtml("@@Core @Ada #42\n"),
        );
    }

    public function testMentionDoesNotStartInsideAWord(): void
    {
        $factory = $this->factory();

        self::assertSame(
            "<p>x@ada _@ada <a href=\"https://github.com/ada\">@ada</a></p>\n",
            $factory->toHtml("x@ada _@ada @ada\n"),
        );
    }

    public function testMultiBytePrefixDeclinesAShortCandidate(): void
    {
        $factory = Markdown::commonmark()->with(new MentionExtension(
            MentionDefinition::links('team', '@@', '[a-z]+', 'https://example.com/teams/%s'),
        ));

        self::assertSame("<p>@ada</p>\n", $factory->toHtml("@ada\n"));
    }

    public function testResolverCanCustomizeTheLabelAndTitle(): void
    {
        $resolver = new class implements MentionResolver {
            public function resolve(Mention $mention): MentionTarget
            {
                return new MentionTarget(
                    'https://example.com/member/' . rawurlencode($mention->identifier),
                    'Member ' . $mention->identifier,
                    'Open profile',
                );
            }
        };
        $factory = Markdown::commonmark()->with(new MentionExtension(
            new MentionDefinition('member', '@', '[a-z]+', $resolver),
        ));

        self::assertSame(
            "<p><a href=\"https://example.com/member/ada\" title=\"Open profile\">Member ada</a></p>\n",
            $factory->toHtml("@ada\n"),
        );
    }

    public function testDeclinedResolutionFallsThroughToTheNextDefinition(): void
    {
        $declining = new class implements MentionResolver {
            public function resolve(Mention $mention): ?MentionTarget
            {
                return null;
            }
        };
        $factory = Markdown::commonmark()->with(new MentionExtension(
            new MentionDefinition('local', '@', '[a-z]+', $declining),
            MentionDefinition::links('remote', '@', '[a-z]+', 'https://example.com/%s'),
        ));

        self::assertSame(
            "<p><a href=\"https://example.com/ada\">@ada</a></p>\n",
            $factory->toHtml("@ada\n"),
        );
    }

    public function testMentionUsesTheActiveUrlPolicy(): void
    {
        $resolver = new class implements MentionResolver {
            public function resolve(Mention $mention): MentionTarget
            {
                return new MentionTarget('javascript:alert(1)');
            }
        };
        $factory = Markdown::commonmark()->with(new MentionExtension(
            new MentionDefinition('user', '@', '[a-z]+', $resolver),
        ));

        self::assertSame("<p><a href=\"\">@ada</a></p>\n", $factory->toHtml("@ada\n"));
    }

    public function testMentionNeverCreatesNestedLinks(): void
    {
        $factory = $this->factory();

        Instrumentation::reset();
        self::assertSame(
            "<p><a href=\"/outer\">Hi @ada</a></p>\n",
            $factory->toHtml("[Hi @ada](/outer)\n"),
        );
        self::assertSame(1, Instrumentation::$fusedInlineFallbacks);
        self::assertSame(1, Instrumentation::$fusedFallbackReasons['link-like-inside-link'] ?? 0);

        self::assertSame(
            "<p><img src=\"/portrait\" alt=\"@ada\" /></p>\n",
            $factory->toHtml("![@ada](/portrait)\n"),
        );
    }

    public function testMentionKeepsItsQualifiedKindAndOriginalRange(): void
    {
        $document = $this->factory()->fromString("# Hi @ada\n");
        $heading = $document->headings()->first();
        $model = $document->model();

        self::assertNotNull($heading);
        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        $mentions = array_values(array_filter(
            [...$model->traversalInlineEvents($heading->id()->ordinal)],
            static fn(array $event): bool => 'mention:user' === $event[1],
        ));

        self::assertCount(1, $mentions);
        self::assertEquals(new SourceRange(5, 9), $mentions[0][2]);
    }

    public function testMentionParserIsOnlyCalledAtItsTrigger(): void
    {
        $factory = $this->factory();

        Instrumentation::reset();
        self::assertSame("<p>Plain text.</p>\n", $factory->toHtml("Plain text.\n"));
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);

        Instrumentation::reset();
        self::assertSame(
            "<p><a href=\"https://github.com/ada\">@ada</a> and @.</p>\n",
            $factory->toHtml("@ada and @.\n"),
        );
        self::assertSame(2, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(1, Instrumentation::$extensionInlineRendererLookups);
        self::assertSame(1, Instrumentation::$fusedInlineRenders);
    }

    public function testIdentifierLengthIsBounded(): void
    {
        $factory = Markdown::commonmark()->with(new MentionExtension(
            MentionDefinition::links('user', '@', '[a-z]+', 'https://example.com/%s', 3),
        ));

        self::assertSame("<p>@abcd</p>\n", $factory->toHtml("@abcd\n"));
        self::assertSame(
            "<p><a href=\"https://example.com/abc\">@abc</a></p>\n",
            $factory->toHtml("@abc\n"),
        );
    }

    public function testUrlTemplateEncodesTheIdentifier(): void
    {
        $resolver = new UrlTemplateMentionResolver('https://example.com/%s');

        self::assertEquals(
            new MentionTarget('https://example.com/Ada%20Lovelace'),
            $resolver->resolve(new Mention('user', '@', 'Ada Lovelace')),
        );
    }

    public function testInvalidDefinitionsAreRejected(): void
    {
        $resolver = new UrlTemplateMentionResolver('https://example.com/%s');

        $invalid = [
            static fn(): MentionDefinition => new MentionDefinition('Bad', '@', '[a-z]+', $resolver),
            static fn(): MentionDefinition => new MentionDefinition('user', '', '[a-z]+', $resolver),
            static fn(): MentionDefinition => new MentionDefinition('user', '@', '[', $resolver),
            static fn(): MentionDefinition => new MentionDefinition('user', '@', '.*', $resolver),
            static fn(): MentionDefinition => new MentionDefinition('user', '@', '[a-z]+', $resolver, 0),
            static fn(): MentionDefinition => new MentionDefinition('user', '@', "~#%!;\x01", $resolver),
        ];

        foreach ($invalid as $create) {
            try {
                $create();
                self::fail('Expected an invalid mention definition to be rejected.');
            } catch (InvalidMarkdownArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testInvalidExtensionAndTemplateConfigurationsAreRejected(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        new MentionExtension();
    }

    public function testDuplicateMentionTypesAreRejected(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        new MentionExtension(
            MentionDefinition::links('user', '@', '[a-z]+', 'https://example.com/%s'),
            MentionDefinition::links('user', '@@', '[a-z]+', 'https://example.com/team/%s'),
        );
    }

    public function testUrlTemplateRequiresExactlyOnePlaceholder(): void
    {
        foreach (['https://example.com/user', 'https://example.com/%s/%s'] as $template) {
            try {
                new UrlTemplateMentionResolver($template);
                self::fail('Expected an invalid mention URL template to be rejected.');
            } catch (InvalidMarkdownArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    private function factory(): \Alto\Markdown\MarkdownFactory
    {
        return Markdown::commonmark()->with(new MentionExtension(
            MentionDefinition::links(
                'user',
                '@',
                '[A-Z0-9](?:[A-Z0-9-]{0,38})(?![A-Z0-9-])',
                'https://github.com/%s',
            ),
        ));
    }
}
