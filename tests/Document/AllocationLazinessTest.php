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

use Alto\Markdown\Markdown;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\TestCase;

final class AllocationLazinessTest extends TestCase
{
    public function testReadingHeadingsInMixedLargeDocumentAllocatesOnlyHeadingHandles(): void
    {
        $source = self::mixedDocument(500);
        $document = Markdown::github()->fromString($source);

        Instrumentation::reset();
        $texts = [];

        foreach ($document->headings() as $heading) {
            $texts[] = $heading->text();
        }

        self::assertCount(100, $texts);

        foreach ($texts as $text) {
            self::assertIsString($text);
        }

        self::assertSame(100, Instrumentation::$headingHandles);
        self::assertSame(100, Instrumentation::$nodeHandles);
        self::assertSame(0, Instrumentation::$linkHandles);
        self::assertSame(0, Instrumentation::$imageHandles);
        self::assertSame(0, Instrumentation::$codeBlockHandles);
        self::assertSame(0, Instrumentation::$sectionHandles);
        self::assertSame(100, Instrumentation::$inlineParses);
    }

    public function testIteratingHeadingsWithoutReadingTextDoesNotParseInlines(): void
    {
        $document = Markdown::github()->fromString(self::mixedDocument(50));

        Instrumentation::reset();
        $headings = $document->headings()->all();

        self::assertContainsOnlyInstancesOf(Heading::class, $headings);
        self::assertCount(10, $headings);
        self::assertSame(10, Instrumentation::$headingHandles);
        self::assertSame(0, Instrumentation::$inlineParses);
    }

    private static function mixedDocument(int $blocks): string
    {
        $parts = [];

        for ($i = 0; $i < $blocks; ++$i) {
            if (0 === $i % 5) {
                $parts[] = '## Heading '.$i.' with `code`';
            } elseif (1 === $i % 5) {
                $parts[] = 'Paragraph with [link '.$i.'](https://example.com/'.$i.') and ![image '.$i.'](/'.$i.'.png).';
            } elseif (2 === $i % 5) {
                $parts[] = "```php\n".'echo '.$i.";\n```";
            } elseif (3 === $i % 5) {
                $parts[] = 'Plain paragraph '.$i.'.';
            } else {
                $parts[] = "- item {$i}\n- item ".($i + 1);
            }
        }

        return implode("\n\n", $parts)."\n";
    }
}
