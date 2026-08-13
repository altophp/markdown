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

namespace Alto\Markdown\Tests\Profile;

use Alto\Markdown\Extension\FrontMatter\FrontMatterExtension;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Extension\GitHub\GitHubAlertsExtension;
use Alto\Markdown\Extension\NodeKindExtensionInterface;
use Alto\Markdown\Parser\Block\AtxHeadingParser;
use Alto\Markdown\Parser\Block\BlockQuoteParser;
use Alto\Markdown\Parser\Block\FencedCodeParser;
use Alto\Markdown\Parser\Block\HtmlBlockParser;
use Alto\Markdown\Parser\Block\IndentedCodeParser;
use Alto\Markdown\Parser\Block\LinkReferenceDefinitionParser;
use Alto\Markdown\Parser\Block\ListItemParser;
use Alto\Markdown\Parser\Block\ListParser;
use Alto\Markdown\Parser\Block\SetextHeadingParser;
use Alto\Markdown\Parser\Block\ThematicBreakParser;
use Alto\Markdown\Parser\Inline\AutolinkParser;
use Alto\Markdown\Parser\Inline\BackslashEscapeParser;
use Alto\Markdown\Parser\Inline\CodeSpanParser;
use Alto\Markdown\Parser\Inline\EntityReferenceParser;
use Alto\Markdown\Parser\Inline\RawHtmlParser;
use Alto\Markdown\Profile\CommonMarkProfile;
use Alto\Markdown\Profile\Feature;
use Alto\Markdown\Profile\GfmProfile;
use Alto\Markdown\Profile\GitHubProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use PHPUnit\Framework\TestCase;

final class ProfileCompilerTest extends TestCase
{
    public function testProfileFeatureSetsAreDistinct(): void
    {
        $commonmark = new CommonMarkProfile();
        $gfm = new GfmProfile();
        $github = new GitHubProfile();

        self::assertTrue($commonmark->supports(Feature::CommonMark));
        self::assertFalse($commonmark->supports(Feature::Tables));

        self::assertTrue($gfm->supports(Feature::Tables));
        self::assertTrue($gfm->supports(Feature::TagFilter));
        self::assertFalse($gfm->supports(Feature::GitHubAlerts));

        self::assertTrue($github->supports(Feature::Tables));
        self::assertTrue($github->supports(Feature::GitHubAlerts));
    }

    public function testCompilerPreservesCoreConstructOrder(): void
    {
        $compiled = new ProfileCompiler()->compile(new CommonMarkProfile());

        self::assertSame(
            [
                BlockQuoteParser::class,
                AtxHeadingParser::class,
                FencedCodeParser::class,
                HtmlBlockParser::class,
                SetextHeadingParser::class,
                ThematicBreakParser::class,
                ListParser::class,
                ListItemParser::class,
                IndentedCodeParser::class,
                LinkReferenceDefinitionParser::class,
            ],
            array_map(static fn(object $construct): string => $construct::class, $compiled->blockConstructs()),
        );
        self::assertSame(
            [
                BackslashEscapeParser::class,
                EntityReferenceParser::class,
                CodeSpanParser::class,
                AutolinkParser::class,
                RawHtmlParser::class,
            ],
            array_map(static fn(object $construct): string => $construct::class, $compiled->inlineConstructs()),
        );
    }

    public function testCompilerReservesExtensionNodeKindsInProfileOrder(): void
    {
        $gfm = new ProfileCompiler()->compile(new GfmProfile());
        $github = new ProfileCompiler()->compile(new GitHubProfile());

        self::assertTrue($gfm->supports(Feature::ExtendedAutolinks));
        self::assertFalse($gfm->supports(Feature::GitHubAlerts));
        self::assertSame(
            GfmExtension::TABLE_KIND,
            $gfm->nodeKinds->find(GfmExtension::TABLE_KIND)?->name,
        );
        self::assertNull($gfm->nodeKinds->find('gfm:task-list-item'));
        self::assertNull($gfm->nodeKinds->find('gfm:strikethrough'));

        self::assertTrue($github->supports(Feature::GitHubAlerts));
        self::assertSame(
            GfmExtension::TABLE_KIND,
            $github->nodeKinds->find(GfmExtension::TABLE_KIND)?->name,
        );
        self::assertSame(
            GitHubAlertsExtension::ALERT_KIND,
            $github->nodeKinds->find(GitHubAlertsExtension::ALERT_KIND)?->name,
        );
        self::assertTrue($github->supports(Feature::FrontMatter));
        self::assertFalse($gfm->supports(Feature::FrontMatter));
        self::assertSame(
            FrontMatterExtension::BLOCK_KIND,
            $github->nodeKinds->find(FrontMatterExtension::BLOCK_KIND)?->name,
        );
    }

    public function testCompiledExtensionsExposeReservedNodeKinds(): void
    {
        $compiled = new ProfileCompiler()->compile(new GitHubProfile());
        $extensions = $compiled->extensions();

        self::assertSame(['core', 'gfm', 'github', 'frontmatter'], array_map(static fn($extension): string => $extension->name(), $extensions));
        self::assertContainsOnlyInstancesOf(NodeKindExtensionInterface::class, $extensions);
        self::assertInstanceOf(NodeKindExtensionInterface::class, $extensions[0]);
        self::assertInstanceOf(NodeKindExtensionInterface::class, $extensions[1]);
        self::assertInstanceOf(NodeKindExtensionInterface::class, $extensions[2]);
        self::assertInstanceOf(NodeKindExtensionInterface::class, $extensions[3]);
        self::assertSame([], [...$extensions[0]->nodeKinds()]);
        self::assertSame(
            [GfmExtension::TABLE_KIND],
            array_map(static fn($kind): string => $kind->name, [...$extensions[1]->nodeKinds()]),
        );
        self::assertSame(['github:alert'], array_map(static fn($kind): string => $kind->name, [...$extensions[2]->nodeKinds()]));
        self::assertSame(['frontmatter:block'], array_map(static fn($kind): string => $kind->name, [...$extensions[3]->nodeKinds()]));
    }

    public function testInlineSpecialBytesComeFromCompiledConstructs(): void
    {
        $compiled = new ProfileCompiler()->compile(new CommonMarkProfile());

        foreach (["\n", '*', '_', '[', ']', '!', '\\', '&', '`', '<'] as $byte) {
            self::assertStringContainsString($byte, $compiled->inlineSpecialBytes);
        }
    }
}
