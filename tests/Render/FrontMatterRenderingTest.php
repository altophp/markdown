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

namespace Alto\Markdown\Tests\Render;

use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;
use Alto\Markdown\Traversal\TapeDocumentTraversal;
use Alto\Markdown\Traversal\TraversalVisitor;
use PHPUnit\Framework\TestCase;

final class FrontMatterRenderingTest extends TestCase
{
    private const string SOURCE = "---\ntitle: Hello\nlayout: page\n---\n\n# Heading\n\nBody text\n";

    /**
     * @var list<string>
     */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->paths = [];
    }

    public function testFrontMatterIsAbsentFromDocumentHtml(): void
    {
        $html = Markdown::github()->fromString(self::SOURCE)->toHtml();

        self::assertSame("<h1>Heading</h1>\n<p>Body text</p>\n", $html);
        self::assertStringNotContainsString('title', $html);
    }

    public function testBothHtmlLanesAgree(): void
    {
        $markdown = Markdown::github();

        self::assertSame(
            $markdown->fromString(self::SOURCE)->toHtml(),
            $markdown->toHtml(self::SOURCE),
        );
    }

    public function testHtmlLanesAgreeOnTomlAndOnUnterminatedBlocks(): void
    {
        $markdown = Markdown::github();

        foreach (["+++\na = 1\n+++\n\nBody text\n", "---\na: 1\n\nBody text\n", "---\n---\n# Only\n"] as $source) {
            self::assertSame(
                $markdown->fromString($source)->toHtml(),
                $markdown->toHtml($source),
                'Direct and document HTML must agree.',
            );
        }
    }

    public function testToMarkdownRoundTripsTheDocumentByteForByte(): void
    {
        self::assertSame(self::SOURCE, Markdown::github()->fromString(self::SOURCE)->toMarkdown());
    }

    public function testToMarkdownRoundTripsTheBlockWhenNoBlankLineFollows(): void
    {
        // The renderer normalizes the separator between two blocks, as it does
        // everywhere else, but the opaque block's own bytes are untouched.
        $source = "---\ntitle: Hello\n---\n# Heading\n";
        $rendered = Markdown::github()->fromString($source)->toMarkdown(new RenderOptions());

        self::assertStringStartsWith("---\ntitle: Hello\n---\n", $rendered);
        self::assertSame("---\ntitle: Hello\n---\n\n# Heading\n", $rendered);
    }

    public function testToMarkdownKeepsTomlAndAwkwardContentVerbatim(): void
    {
        $source = "+++\ntitle = \"a --- b\"\nweird   =    [1,2]\n+++\n\nBody text\n";

        self::assertSame($source, Markdown::github()->fromString($source)->toMarkdown());
    }

    public function testSavePreservesFrontMatterBytesWhileEditingTheBody(): void
    {
        $path = $this->writeTempFile("---\ntitle: Hello   \n\ttab: kept\n---\n\n```php\necho \"old\";\n```\n");
        $file = Markdown::github()->open($path);
        $block = $file->codeBlocks('php')->first();
        self::assertNotNull($block);

        $block->replaceCode("echo \"new\";\n");
        $file->save();

        self::assertSame(
            "---\ntitle: Hello   \n\ttab: kept\n---\n\n```php\necho \"new\";\n```\n",
            file_get_contents($path),
        );
    }

    public function testSaveWithNoEditsLeavesCrLfFrontMatterUntouched(): void
    {
        $source = "---\r\ntitle: Hello\r\n---\r\n\r\n# Heading\r\n";
        $path = $this->writeTempFile($source);
        $file = Markdown::github()->open($path);

        $file->save();

        self::assertSame($source, file_get_contents($path));
        self::assertTrue($file->diff()->isEmpty());
    }

    public function testSaveWritesOnlyTheNewOpaqueFrontMatterContent(): void
    {
        $path = $this->writeTempFile("\xEF\xBB\xBF---\r\ntitle: Old\r\n---\r\n\r\n# Heading\r\n");
        $file = Markdown::github()->open($path);
        $frontMatter = $file->frontMatter();
        self::assertNotNull($frontMatter);

        $frontMatter->replaceContent("title: New\n");
        $file->save();

        self::assertSame(
            "\xEF\xBB\xBF---\r\ntitle: New\r\n---\r\n\r\n# Heading\r\n",
            file_get_contents($path),
        );
        self::assertTrue($file->model()->journal()->isEmpty());
    }

    public function testFrontMatterIsReachableThroughQuery(): void
    {
        $handles = Markdown::github()->fromString(self::SOURCE)
            ->query()
            ->kind('frontmatter:block')
            ->get()
            ->all();

        self::assertCount(1, $handles);
        self::assertSame(0, $handles[0]->range()->startOffset);
        self::assertSame(34, $handles[0]->range()->endOffset);
    }

    public function testFrontMatterAppearsInTraversal(): void
    {
        $document = Markdown::github()->fromString(self::SOURCE);
        $visitor = new FrontMatterRecordingVisitor();

        new TapeDocumentTraversal()->traverse($document->model(), $visitor);

        self::assertSame(
            ['document', 'frontmatter:block', 'atx-heading', 'paragraph'],
            $visitor->kinds,
        );
    }

    public function testLintAndFormatLeaveOpaqueTrailingSpacesAlone(): void
    {
        $source = "---\ntitle: Hello   \n---\n\n# Heading\n";
        $document = Markdown::github()->fromString($source);

        self::assertTrue($document->lint(LintConfig::recommended())->isClean());
        self::assertSame($source, $document->format()->toMarkdown());
    }

    public function testFrontMatterDoesNotHideTheDocumentTitleFromLint(): void
    {
        $withTitle = Markdown::github()->fromString("---\ntitle: Hello\n---\n\n# Heading\n");
        $withoutTitle = Markdown::github()->fromString("---\ntitle: Hello\n---\n\nBody text\n");

        self::assertTrue($withTitle->lint((new LintConfig())->withRule('require-title'))->isClean());
        self::assertFalse($withoutTitle->lint((new LintConfig())->withRule('require-title'))->isClean());
    }

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'alto-front-matter-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }
}

final class FrontMatterRecordingVisitor implements TraversalVisitor
{
    /**
     * @var list<string>
     */
    public array $kinds = [];

    public function enterBlock(BlockEvent $event): void
    {
        $this->kinds[] = $event->kind->name;
    }

    public function leaveBlock(BlockEvent $event): void
    {
    }

    public function inline(InlineEvent $event): void
    {
    }
}
