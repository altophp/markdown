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

use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectHtmlConversionTest extends TestCase
{
    /**
     * @param callable(): MarkdownFactory $factory
     */
    #[DataProvider('provideDocuments')]
    public function testDirectConversionMatchesDocumentRendering(callable $factory, string $source): void
    {
        $markdown = $factory();

        self::assertSame(
            $markdown->fromString($source)->toHtml(),
            $markdown->toHtml($source),
        );
    }

    /**
     * @return iterable<string, array{callable(): MarkdownFactory, string}>
     */
    public static function provideDocuments(): iterable
    {
        yield 'commonmark' => [
            static fn (): MarkdownFactory => Markdown::commonmark(),
            "# Title\n\nA **strong** [reference][id].\n\n1. first\n2. second\n\n```php\necho \"<ok>\";\n```\n\n[id]: /target \"Title\"\n",
        ];
        yield 'gfm' => [
            static fn (): MarkdownFactory => Markdown::gfm(),
            "- [x] ~~done~~\n\n| A | B |\n| :- | -: |\n| `x` | https://example.com |\n",
        ];
        yield 'github' => [
            static fn (): MarkdownFactory => Markdown::github(),
            "> [!WARNING]\n> Be **careful** with <title>.\n",
        ];
    }

    public function testDirectConversionDoesNotConstructDocumentState(): void
    {
        $factory = Markdown::github();
        Instrumentation::reset();

        self::assertSame(
            "<h1>Title</h1>\n<p>A <a href=\"/target\">reference</a>.</p>\n",
            $factory->toHtml("# Title\n\nA [reference][id].\n\n[id]: /target\n"),
        );
        self::assertSame(1, Instrumentation::$syntaxParses);
        self::assertSame(0, Instrumentation::$documentWorkspaces);
        self::assertSame(0, Instrumentation::$workspaceTapeClones);
        self::assertSame(1, Instrumentation::$referenceMapClones);
        self::assertSame(0, Instrumentation::$inlineCaches);
        self::assertSame(0, Instrumentation::$editJournals);
        self::assertSame(0, Instrumentation::$nodeHandles);
    }
}
