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

use Alto\Markdown\Exception\BlockCountLimitException;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\MarkdownExceptionInterface;
use Alto\Markdown\Exception\ParseLimitException;
use Alto\Markdown\Exception\SourceSizeLimitException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseOptions;
use PHPUnit\Framework\TestCase;

final class ParseBudgetTest extends TestCase
{
    public function testSourceAtTheConfiguredLimitParses(): void
    {
        $markdown = "# A\n";
        $options = (new ParseOptions())->withMaxSourceBytes(\strlen($markdown));

        self::assertSame("<h1>A</h1>\n", Markdown::commonmark()->toHtml($markdown, $options));
    }

    public function testSourcePastTheConfiguredLimitIsRefusedBeforeParsing(): void
    {
        Instrumentation::reset();
        $options = (new ParseOptions())->withMaxSourceBytes(4);

        try {
            Markdown::commonmark()->toHtml('12345', $options);
            self::fail('Expected the source-size budget to be enforced.');
        } catch (SourceSizeLimitException $exception) {
            self::assertInstanceOf(MarkdownExceptionInterface::class, $exception);
            self::assertInstanceOf(ParseLimitException::class, $exception);
            self::assertSame(4, $exception->maxSourceBytes);
            self::assertSame(5, $exception->sourceBytes);
            self::assertSame(0, Instrumentation::$syntaxParses);
        }
    }

    public function testDocumentLaneEnforcesTheSourceBudget(): void
    {
        $options = (new ParseOptions())->withMaxSourceBytes(4);

        $this->expectException(SourceSizeLimitException::class);

        Markdown::commonmark()->fromString('12345', $options);
    }

    public function testBlocksAtTheConfiguredLimitParse(): void
    {
        $markdown = "# A\n\nB\n";
        $options = (new ParseOptions())->withMaxBlockCount(2);

        self::assertSame("<h1>A</h1>\n<p>B</p>\n", Markdown::commonmark()->toHtml($markdown, $options));
    }

    public function testBlockPastTheConfiguredLimitIsRefusedAtItsSourceOffset(): void
    {
        $markdown = "# A\n\nB\n";
        $options = (new ParseOptions())->withMaxBlockCount(1);

        try {
            Markdown::commonmark()->toHtml($markdown, $options);
            self::fail('Expected the block-count budget to be enforced.');
        } catch (BlockCountLimitException $exception) {
            self::assertInstanceOf(MarkdownExceptionInterface::class, $exception);
            self::assertInstanceOf(ParseLimitException::class, $exception);
            self::assertSame(1, $exception->maxBlockCount);
            self::assertSame(2, $exception->attemptedBlockCount);
            self::assertSame(5, $exception->byteOffset);
        }
    }

    public function testDocumentLaneEnforcesTheBlockBudget(): void
    {
        $options = (new ParseOptions())->withMaxBlockCount(1);

        $this->expectException(BlockCountLimitException::class);

        Markdown::commonmark()->fromString("# A\n\nB\n", $options);
    }

    public function testReferenceDefinitionsCountAsSemanticBlocks(): void
    {
        $markdown = "[a]: /a\n[b]: /b\n";
        $options = (new ParseOptions())->withMaxBlockCount(2);

        self::assertSame('', Markdown::commonmark()->toHtml($markdown, $options));

        $this->expectException(BlockCountLimitException::class);

        Markdown::commonmark()->toHtml($markdown, $options->withMaxBlockCount(1));
    }

    public function testSetextReplacementDoesNotConsumeAnotherBlock(): void
    {
        $options = (new ParseOptions())->withMaxBlockCount(1);

        self::assertSame("<h1>Title</h1>\n", Markdown::commonmark()->toHtml("Title\n=====\n", $options));
    }

    public function testLimitsCanBeDisabledExplicitly(): void
    {
        $options = (new ParseOptions())
            ->withMaxSourceBytes(1)
            ->withMaxBlockCount(1)
            ->withMaxInlineCount(1)
            ->withMaxReferenceCount(1)
            ->withUnboundedSourceBytes()
            ->withUnboundedBlockCount()
            ->withUnboundedInlineCount()
            ->withUnboundedReferenceCount();

        self::assertSame("<h1>A</h1>\n<p>B</p>\n", Markdown::commonmark()->toHtml("# A\n\nB\n", $options));
    }

    public function testWithersPreserveTheOtherLimits(): void
    {
        $options = (new ParseOptions(
            maxNestingDepth: 10,
            maxSourceBytes: 20,
            maxBlockCount: 30,
            maxInlineCount: 40,
            maxReferenceCount: 50,
        ))
            ->withMaxNestingDepth(11)
            ->withMaxSourceBytes(21)
            ->withMaxBlockCount(31)
            ->withMaxInlineCount(41)
            ->withMaxReferenceCount(51);

        self::assertSame(11, $options->maxNestingDepth);
        self::assertSame(21, $options->maxSourceBytes);
        self::assertSame(31, $options->maxBlockCount);
        self::assertSame(41, $options->maxInlineCount);
        self::assertSame(51, $options->maxReferenceCount);
    }

    public function testNegativeSourceLimitIsRejected(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        new ParseOptions(maxSourceBytes: -1);
    }

    public function testNegativeBlockLimitIsRejected(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        new ParseOptions(maxBlockCount: -1);
    }

    public function testNegativeNestingLimitIsRejected(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        new ParseOptions(maxNestingDepth: -1);
    }

    public function testNegativeInlineLimitIsRejected(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        new ParseOptions(maxInlineCount: -1);
    }

    public function testNegativeReferenceLimitIsRejected(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        new ParseOptions(maxReferenceCount: -1);
    }
}
