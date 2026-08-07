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

namespace Alto\Markdown\Tests\Formatter;

use Alto\Markdown\Markdown;
use Alto\Markdown\Render\MarkdownStyle;
use PHPUnit\Framework\TestCase;

final class DocumentFormatTest extends TestCase
{
    public function testFormatReturnsDocumentAndKeepsConformingSourceUnchanged(): void
    {
        $document = Markdown::github()->fromString("# Title\n");
        $returned = $document->format();

        self::assertSame($document, $returned);
        self::assertFalse($document->hasChanges());
        self::assertTrue($document->diff()->isEmpty());
    }

    public function testFormatAcceptsExplicitStyle(): void
    {
        $document = Markdown::github()->fromString("* Item\n");
        $style = new MarkdownStyle(bulletMarker: '+');
        $returned = $document->format($style);

        self::assertSame($document, $returned);
        self::assertTrue($document->hasChanges());
        self::assertSame("+ Item\n", $document->toMarkdown());
    }

    public function testProfileStylePresetsAreStable(): void
    {
        $expected = new MarkdownStyle();

        self::assertEquals($expected, MarkdownStyle::commonmark());
        self::assertEquals($expected, MarkdownStyle::gfm());
        self::assertEquals($expected, MarkdownStyle::github());
        self::assertEquals(MarkdownStyle::commonmark(), Markdown::commonmark()->fromString('')->profile()->style());
        self::assertEquals(MarkdownStyle::gfm(), Markdown::gfm()->fromString('')->profile()->style());
        self::assertEquals(MarkdownStyle::github(), Markdown::github()->fromString('')->profile()->style());
    }
}
