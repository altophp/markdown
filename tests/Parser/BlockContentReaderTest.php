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

namespace Alto\Markdown\Tests\Parser;

use Alto\Markdown\Parser\Block\BlockContentReader;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\TestCase;

final class BlockContentReaderTest extends TestCase
{
    public function testContentWithoutPayloadUsesTheBlockRange(): void
    {
        $buffer = new SourceBuffer("text\n");
        $tape = new ParseTape();
        $document = $tape->allocateClosed(BlockKind::DOCUMENT, ParseTape::NONE, 0, 5, 0);
        $paragraph = $tape->appendChild(
            BlockKind::PARAGRAPH,
            $document,
            ParseTape::NONE,
            0,
            4,
            0,
        );
        $reader = new BlockContentReader($buffer, $tape);

        self::assertSame([[0, 4, 0]], $reader->contentPairs($paragraph));
        self::assertSame('text', $reader->inlineMarkdownSource($paragraph));
    }

    public function testRootHtmlBlockUsesItsContiguousSourceRange(): void
    {
        $buffer = new SourceBuffer("<b>\n");
        $tape = new ParseTape();
        $document = $tape->allocateClosed(BlockKind::DOCUMENT, ParseTape::NONE, 0, 4, 0);
        $html = $tape->appendChild(
            BlockKind::HTML_BLOCK,
            $document,
            ParseTape::NONE,
            0,
            4,
            0,
            6,
            '0:3',
        );

        self::assertSame("<b>\n", new BlockContentReader($buffer, $tape)->htmlBlockSource($html));
    }
}
