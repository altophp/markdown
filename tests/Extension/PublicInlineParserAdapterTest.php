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

namespace Alto\Markdown\Tests\Extension;

use Alto\Markdown\Exception\InvalidInlineResultException;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;
use Alto\Markdown\Parser\Inline\ExtensionInlineContext;
use Alto\Markdown\Parser\Inline\InlineContent;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Inline\InlineState;
use Alto\Markdown\Parser\Inline\PublicInlineParserAdapter;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicInlineParserAdapterTest extends TestCase
{
    public function testAdapterEmitsAValidatedLeafNode(): void
    {
        [$state, $tape] = $this->state('^^marked^^');
        $adapter = new PublicInlineParserAdapter(24, new AdapterMarkParser());

        self::assertTrue($adapter->tryParse($state));

        $node = $tape->firstChildOrdinal(0);
        self::assertSame(24, $tape->kindId($node));
        self::assertSame(0, $tape->startOffset($node));
        self::assertSame(10, $tape->endOffset($node));
        self::assertSame('marked', $tape->extensionInlineNode($node)->text);
        self::assertSame('^^marked^^', $tape->payload($node));
        self::assertSame($tape->extensionInlineNode($node), $tape->columns()->extensionInlineNode[$node]);
    }

    public function testDeclinedResultLeavesTheCursorUntouched(): void
    {
        [$state] = $this->state('^plain');

        self::assertFalse(new PublicInlineParserAdapter(24, new AdapterMarkParser())->tryParse($state));
        self::assertSame(0, $state->offset());
    }

    #[DataProvider('invalidResults')]
    public function testAdapterRejectsInvalidResultOffsets(int $endOffset): void
    {
        [$state] = $this->state('^bad');
        $adapter = new PublicInlineParserAdapter(24, new FixedResultParser($endOffset));

        $this->expectException(InvalidInlineResultException::class);
        $this->expectExceptionMessage(\sprintf('Custom inline end offset %d', $endOffset));

        $adapter->tryParse($state);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidResults(): iterable
    {
        yield 'does not advance' => [0];
        yield 'moves backwards' => [-1];
        yield 'past joined content' => [5];
    }

    #[DataProvider('invalidTriggers')]
    public function testAdapterRejectsInvalidTriggers(string $trigger): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('exactly one byte');

        new PublicInlineParserAdapter(24, new FixedResultParser(1, $trigger));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTriggers(): iterable
    {
        yield 'empty' => [''];
        yield 'multiple bytes' => ['^^'];
        yield 'multibyte character' => ['é'];
    }

    #[DataProvider('reservedTriggers')]
    public function testAdapterRejectsAlwaysReservedTriggers(string $trigger): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('is reserved by the active Markdown profile');

        new PublicInlineParserAdapter(24, new FixedResultParser(1, $trigger));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedTriggers(): iterable
    {
        yield 'carriage-return line ending' => ["\r"];
        yield 'line joint' => ["\n"];
        yield 'asterisk emphasis' => ['*'];
        yield 'underscore emphasis' => ['_'];
        yield 'link opener' => ['['];
        yield 'link closer' => [']'];
        yield 'image opener' => ['!'];
    }

    public function testContextProvidesBoundedJoinedContentReads(): void
    {
        $context = new ExtensionInlineContext(
            InlineContent::fromPairs('abcde', [[0, 5, 0]]),
            2,
        );

        self::assertSame(2, $context->offset());
        self::assertSame(5, $context->length());
        self::assertSame('cde', $context->remaining());
        self::assertSame(\ord('b'), $context->byteAt(1));
        self::assertSame('bcd', $context->slice(1, 4));
    }

    public function testContextRejectsAnOutOfBoundsByte(): void
    {
        $context = new ExtensionInlineContext(InlineContent::fromPairs('abc', [[0, 3, 0]]), 0);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('byte offset 3');

        $context->byteAt(3);
    }

    public function testContextRejectsAnOutOfBoundsSlice(): void
    {
        $context = new ExtensionInlineContext(InlineContent::fromPairs('abc', [[0, 3, 0]]), 0);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('slice 2..4');

        $context->slice(2, 4);
    }

    /**
     * @return array{InlineState, ParseTape}
     */
    private function state(string $source): array
    {
        $content = InlineContent::fromPairs($source, [[0, \strlen($source), 0]]);
        $tape = new ParseTape();
        $root = $tape->allocate(InlineKind::ROOT, ParseTape::NONE, 0, 0);

        return [new InlineState($content, $tape, new ReferenceMap(), $root), $tape];
    }
}

final readonly class AdapterMarkParser implements InlineParser
{
    public function triggerByte(): string
    {
        return '^';
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        if (!str_starts_with($context->remaining(), '^^marked^^')) {
            return null;
        }

        return new InlineParseResult(
            $context->offset() + 10,
            new InlineNode('marked'),
        );
    }
}

final readonly class FixedResultParser implements InlineParser
{
    public function __construct(
        private int $endOffset,
        private string $trigger = '^',
    ) {
    }

    public function triggerByte(): string
    {
        return $this->trigger;
    }

    public function tryParse(InlineParseContext $context): InlineParseResult
    {
        return new InlineParseResult($this->endOffset, new InlineNode('bad'));
    }
}
