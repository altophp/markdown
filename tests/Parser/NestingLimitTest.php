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

use Alto\Markdown\Exception\MarkdownExceptionInterface;
use Alto\Markdown\Exception\NestingLimitException;
use Alto\Markdown\Exception\ParseLimitException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\ParseOptions;
use PHPUnit\Framework\TestCase;

/**
 * Deeply nested containers are a denial-of-service vector, not content: no
 * human writes them. Parsing refuses them by default instead of spending
 * time and memory on input it will never serve.
 */
final class NestingLimitTest extends TestCase
{
    /**
     * The document root and the open leaf both occupy a level, so a document
     * that opens N containers around a paragraph reaches depth N + 1.
     */
    private const int MAX_QUOTES_AT_DEFAULT = ParseOptions::DEFAULT_MAX_NESTING_DEPTH - 1;

    public function testNestingAtTheDefaultLimitParses(): void
    {
        $markdown = str_repeat('> ', self::MAX_QUOTES_AT_DEFAULT)."text\n";

        $html = Markdown::gfm()->toHtml($markdown);

        self::assertSame(self::MAX_QUOTES_AT_DEFAULT, substr_count($html, '<blockquote>'));
    }

    public function testNestingPastTheDefaultLimitIsRefused(): void
    {
        $markdown = str_repeat('> ', self::MAX_QUOTES_AT_DEFAULT + 1)."text\n";

        $this->expectException(NestingLimitException::class);

        Markdown::gfm()->toHtml($markdown);
    }

    public function testRefusalIsTypedAsAMarkdownException(): void
    {
        $markdown = str_repeat('> ', 5000)."text\n";

        try {
            Markdown::gfm()->toHtml($markdown);
            self::fail('Expected hostile nesting to be refused.');
        } catch (MarkdownExceptionInterface $exception) {
            self::assertInstanceOf(NestingLimitException::class, $exception);
            self::assertInstanceOf(ParseLimitException::class, $exception);
            self::assertStringContainsString('256', $exception->getMessage());
        }
    }

    /**
     * Refusal must be cheap: a hostile document that nests far past the limit
     * costs no more than the prefix it takes to reach the limit. Parsing the
     * whole 50,000-level input would take orders of magnitude longer.
     */
    public function testRefusalDoesNotParseTheWholeInput(): void
    {
        $markdown = str_repeat('> ', 50000)."text\n";

        $start = hrtime(true);

        try {
            Markdown::gfm()->toHtml($markdown);
            self::fail('Expected hostile nesting to be refused.');
        } catch (NestingLimitException) {
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;
            self::assertLessThan(50.0, $elapsedMs);
        }
    }

    public function testDocumentLaneRefusesTheSameInput(): void
    {
        $markdown = str_repeat('> ', 1000)."text\n";

        $this->expectException(NestingLimitException::class);

        Markdown::gfm()->fromString($markdown);
    }

    public function testTheLimitIsConfigurable(): void
    {
        $markdown = str_repeat('> ', 10)."text\n";
        $options = (new ParseOptions())->withMaxNestingDepth(5);

        $this->expectException(NestingLimitException::class);

        Markdown::gfm()->toHtml($markdown, $options);
    }

    public function testUnboundedModeAcceptsDeepNesting(): void
    {
        $markdown = str_repeat('> ', 1000)."text\n";
        $options = (new ParseOptions())->withUnboundedNestingDepth();

        $html = Markdown::gfm()->toHtml($markdown, $options);

        self::assertSame(1000, substr_count($html, '<blockquote>'));
    }

    public function testRealisticNestingIsUnaffected(): void
    {
        $markdown = "> - item\n>   - nested\n>     > quoted\n";

        $html = Markdown::gfm()->toHtml($markdown);

        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<ul>', $html);
    }
}
