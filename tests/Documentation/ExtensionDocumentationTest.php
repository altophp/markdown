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

namespace Alto\Markdown\Tests\Documentation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionDocumentationTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const array EXTENSIONS = [
        'attributes',
        'code-block-titles',
        'content-slicer',
        'default-attributes',
        'description-lists',
        'embeds',
        'external-links',
        'footnotes',
        'heading-levels',
        'heading-permalinks',
        'highlight',
        'import',
        'include',
        'link-rewriting',
        'mentions',
        'paired-delimiters',
        'smart-punctuation',
        'source',
        'table-of-contents',
        'tabs',
    ];

    #[DataProvider('provideExtensions')]
    public function testEveryExtensionHasACompleteDocumentationPage(string $slug): void
    {
        $contents = $this->read('docs/extensions/' . $slug . '.md');

        self::assertMatchesRegularExpression('/\A# [^\n]+\n\n\S/', $contents);
        self::assertSame(
            ['Install', 'Configure', 'Markdown', 'HTML', 'Options', 'Security'],
            array_slice($this->levelTwoHeadings($contents), 0, 6),
        );
        self::assertStringContainsString("```bash\ncomposer require alto/markdown\n```", $contents);
        self::assertMatchesRegularExpression('/```markdown\n.+?\n```/s', $contents);
        self::assertMatchesRegularExpression('/```html\n.+?\n```/s', $contents);
    }

    #[DataProvider('provideExtensions')]
    public function testEveryExtensionIsLinkedFromBothIndexes(string $slug): void
    {
        self::assertMatchesRegularExpression(
            '/\]\(extensions\/' . $slug . '\.md\)/',
            $this->read('docs/index.md'),
        );
        self::assertMatchesRegularExpression(
            '/\]\(' . $slug . '\.md\)/',
            $this->read('docs/extensions/index.md'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideExtensions(): iterable
    {
        foreach (self::EXTENSIONS as $slug) {
            yield $slug => [$slug];
        }
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 2) . '/' . $path);

        self::assertIsString($contents, $path);

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function levelTwoHeadings(string $contents): array
    {
        $headings = [];
        $fenceCharacter = null;
        $fenceLength = 0;

        foreach (explode("\n", $contents) as $line) {
            if (null !== $fenceCharacter) {
                if (1 === preg_match('/^ {0,3}' . preg_quote($fenceCharacter, '/') . '{' . $fenceLength . ',}[ \t]*$/', $line)) {
                    $fenceCharacter = null;
                }

                continue;
            }

            if (1 === preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $matches)) {
                $fenceCharacter = $matches[1][0];
                $fenceLength = \strlen($matches[1]);

                continue;
            }

            if (1 === preg_match('/^## ([^#].*)$/', $line, $matches)) {
                $headings[] = $matches[1];
            }
        }

        return $headings;
    }
}
