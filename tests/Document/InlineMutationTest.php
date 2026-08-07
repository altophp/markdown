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

namespace Alto\Markdown\Tests\Document;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\StaleHandleException;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use PHPUnit\Framework\TestCase;

final class InlineMutationTest extends TestCase
{
    public function testLinkDestinationMutationLocalizesAReferenceLink(): void
    {
        $source = "\xEF\xBB\xBF[**docs**][guide]\r\n\r\n[guide]: /old \"Guide\"\r\n";
        $document = Markdown::github()->fromString($source);
        $link = $document->links()->first();
        self::assertNotNull($link);
        $originalRange = $link->range();

        $updated = $link->setDestination('https://example.com/a b');
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertFalse($link->exists());
        self::assertSame('docs', $updated->text());
        self::assertSame('https://example.com/a%20b', $updated->destination());
        self::assertSame('Guide', $updated->titleAttribute());
        self::assertSame(
            "\xEF\xBB\xBF[**docs**](https://example.com/a%20b \"Guide\")\r\n\r\n[guide]: /old \"Guide\"\r\n",
            $lowered->bytes,
        );
        self::assertSame([], $lowered->fallbacks);
        self::assertCount(1, $lowered->patches);
        self::assertEquals($originalRange, $lowered->patches[0]->range);
        self::assertSame(
            "<p><a href=\"https://example.com/a%20b\" title=\"Guide\"><strong>docs</strong></a></p>\n",
            $document->toHtml(),
        );

        $this->expectException(StaleHandleException::class);
        $link->destination();
    }

    public function testLinkTitleMutationAddsAndRemovesTheAttribute(): void
    {
        $document = Markdown::github()->fromString("[docs](/guide)\n");
        $link = $document->links()->first();
        self::assertNotNull($link);

        $updated = $link->setTitleAttribute('Read "first"');

        self::assertSame('Read "first"', $updated->titleAttribute());
        self::assertSame("[docs](/guide \"Read \\\"first\\\"\")\n", $document->toMarkdown());

        $document = Markdown::github()->fromString("[docs](/guide 'Old')\n");
        $link = $document->links()->first();
        self::assertNotNull($link);

        $updated = $link->setTitleAttribute(null);

        self::assertNull($updated->titleAttribute());
        self::assertSame("[docs](/guide)\n", $document->toMarkdown());
    }

    public function testImageDestinationMutationPreservesTheAuthoredLabel(): void
    {
        $source = "![*logo*][asset]\n\n[asset]: /old.png \"Asset\"\n";
        $document = Markdown::github()->fromString($source);
        $image = $document->images()->first();
        self::assertNotNull($image);

        $updated = $image->setDestination('/new image.png');

        self::assertSame('logo', $updated->altText());
        self::assertSame('/new%20image.png', $updated->destination());
        self::assertSame('Asset', $updated->titleAttribute());
        self::assertSame(
            "![*logo*](/new%20image.png \"Asset\")\n\n[asset]: /old.png \"Asset\"\n",
            $document->toMarkdown(),
        );
        self::assertSame(
            "<p><img src=\"/new%20image.png\" alt=\"logo\" title=\"Asset\" /></p>\n",
            $document->toHtml(),
        );
    }

    public function testImageAlternativeTextMutationIsPlainTextAndReadThrough(): void
    {
        $document = Markdown::github()->fromString("![old](/image.png \"Asset\")\n");
        $image = $document->images()->first();
        self::assertNotNull($image);

        $updated = $image->setAltText('API [v2] *logo*');

        self::assertSame('API [v2] *logo*', $updated->altText());
        self::assertSame(
            "![API \\[v2\\] \\*logo\\*](/image.png \"Asset\")\n",
            $document->toMarkdown(),
        );
        self::assertSame(
            "<p><img src=\"/image.png\" alt=\"API [v2] *logo*\" title=\"Asset\" /></p>\n",
            $document->toHtml(),
        );
        self::assertTrue($document->lint((new LintConfig())->withRule('require-image-alt'))->isClean());
    }

    public function testMultipleMutationsOnOneLinkRemainSemanticallyStable(): void
    {
        $document = Markdown::github()->fromString("[docs][guide]\n\n[guide]: /old\n");
        $link = $document->links()->first();
        self::assertNotNull($link);

        $link = $link->setDestination('/new');
        $link->setTitleAttribute('Guide');
        $markdown = $document->toMarkdown();
        $reparsed = Markdown::github()->fromString($markdown);
        $reparsedLink = $reparsed->links()->first();

        self::assertSame("[docs](/new \"Guide\")\n\n[guide]: /old\n", $markdown);
        self::assertNotNull($reparsedLink);
        self::assertSame('/new', $reparsedLink->destination());
        self::assertSame('Guide', $reparsedLink->titleAttribute());
    }

    public function testMultipleImageMutationsPreserveTheAlternativeOverride(): void
    {
        $document = Markdown::github()->fromString("![old](/old.png)\n");
        $image = $document->images()->first();
        self::assertNotNull($image);

        $image = $image->setAltText('');
        $image->setDestination('/new.png');

        self::assertSame("![](/new.png)\n", $document->toMarkdown());
        self::assertSame("<p><img src=\"/new.png\" alt=\"\" /></p>\n", $document->toHtml());
        self::assertCount(
            1,
            $document->lint((new LintConfig())->withRule('require-image-alt')),
        );
    }

    public function testEquivalentInlineMutationsAreNoOps(): void
    {
        $document = Markdown::github()->fromString("[docs](/guide \"Guide\") ![logo](/logo.png)\n");
        $link = $document->links()->first();
        $image = $document->images()->first();
        self::assertNotNull($link);
        self::assertNotNull($image);

        self::assertSame($link, $link->setDestination('/guide'));
        self::assertSame($link, $link->setTitleAttribute('Guide'));
        self::assertSame($image, $image->setDestination('/logo.png'));
        self::assertSame($image, $image->setAltText('logo'));
        self::assertTrue($document->model()->journal()->isEmpty());
    }

    public function testInlineMutationsRejectUnserializableValues(): void
    {
        $document = Markdown::github()->fromString("[docs](/guide) ![logo](/logo.png)\n");
        $link = $document->links()->first();
        self::assertNotNull($link);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Link title must be a single line');

        $link->setTitleAttribute("first\nsecond");
    }

    public function testLinkDestinationRejectsNullBytes(): void
    {
        $document = Markdown::github()->fromString("[docs](/guide)\n");
        $link = $document->links()->first();
        self::assertNotNull($link);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Link destination must not contain a null byte');

        $link->setDestination("https://example.com/\x00");
    }

    public function testImageAlternativeTextRejectsLineBreaks(): void
    {
        $document = Markdown::github()->fromString("![logo](/logo.png)\n");
        $image = $document->images()->first();
        self::assertNotNull($image);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Image alternative text must be a single line');

        $image->setAltText("first\rsecond");
    }
}
