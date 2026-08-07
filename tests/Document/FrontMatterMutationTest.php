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
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

final class FrontMatterMutationTest extends TestCase
{
    public function testReplaceContentChangesOnlyOpaqueContentBytes(): void
    {
        $source = "\xEF\xBB\xBF--- \r\nold: 1\r\n---\t\r\n\r\nBody.\r\n";
        $document = Markdown::github()->fromString($source);
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $updated = $frontMatter->replaceContent("title: New\nlist:\n  - one");
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertFalse($frontMatter->exists());
        self::assertSame('---', $updated->fence());
        self::assertSame("title: New\r\nlist:\r\n  - one\r\n", $updated->content());
        self::assertSame(
            "--- \r\ntitle: New\r\nlist:\r\n  - one\r\n---\t\r\n",
            $updated->text(),
        );
        self::assertSame(
            "\xEF\xBB\xBF--- \r\ntitle: New\r\nlist:\r\n  - one\r\n---\t\r\n\r\nBody.\r\n",
            $lowered->bytes,
        );
        self::assertSame([], $lowered->fallbacks);
        self::assertCount(1, $lowered->patches);
        self::assertSame(9, $lowered->patches[0]->range->startOffset);
        self::assertSame(17, $lowered->patches[0]->range->endOffset);
        self::assertSame("<p>Body.</p>\n", $document->toHtml());

        $this->expectException(StaleHandleException::class);
        $frontMatter->content();
    }

    public function testReplaceContentSupportsEmptyTomlAndLoneCr(): void
    {
        $document = Markdown::github()->fromString("+++\ra = 1\r+++\rBody.\r");
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $updated = $frontMatter->replaceContent('');

        self::assertSame('+++', $updated->fence());
        self::assertSame('', $updated->content());
        self::assertSame("+++\r+++\rBody.\r", $document->toMarkdown());
    }

    public function testEquivalentNormalizedContentIsANoOp(): void
    {
        $document = Markdown::github()->fromString("---\r\na: 1\r\n---\r\n");
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $updated = $frontMatter->replaceContent("a: 1\n");

        self::assertSame($frontMatter, $updated);
        self::assertTrue($frontMatter->exists());
        self::assertTrue($document->model()->journal()->isEmpty());
    }

    public function testContentThatWouldCloseTheFenceIsRejected(): void
    {
        $document = Markdown::github()->fromString("---\na: 1\n---\n");
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('must not contain a line that closes its fence');

        $frontMatter->replaceContent("a: 2\n---  \nb: 3\n");
    }

    public function testOpaqueInvalidYamlIsAcceptedAndReparses(): void
    {
        $document = Markdown::github()->fromString("---\na: 1\n---\n# Heading\n");
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $frontMatter = $frontMatter->replaceContent("  : [oops\n\ttab: \"unclosed");
        $markdown = $document->toMarkdown();
        $reparsed = Markdown::github()->fromString($markdown);

        self::assertSame("  : [oops\n\ttab: \"unclosed\n", $frontMatter->content());
        self::assertSame($frontMatter->content(), $reparsed->frontMatter()?->content());
        self::assertStringStartsWith(
            "---\n  : [oops\n\ttab: \"unclosed\n---\n",
            $document->toMarkdown(new RenderOptions()),
        );
    }

    public function testRepeatedContentReplacementUsesSafeFallback(): void
    {
        $document = Markdown::github()->fromString("---\na: 1\n---\nBody.\n");
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $frontMatter = $frontMatter->replaceContent("a: 2\n");
        $frontMatter->replaceContent("a: 3\n");
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        self::assertSame("---\na: 3\n---\nBody.\n", $lowered->bytes);
        self::assertCount(1, $lowered->fallbacks);
        self::assertSame('ancestor', $lowered->fallbacks[0]->kind);
    }
}
