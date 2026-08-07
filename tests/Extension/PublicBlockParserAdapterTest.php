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

use Alto\Markdown\Exception\InvalidBlockResultException;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockContinueResult;
use Alto\Markdown\Extension\Block\BlockParser;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockStartResult;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Parser\Block\ExtensionBlockContext;
use Alto\Markdown\Parser\Block\PublicBlockParserAdapter;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use PHPUnit\Framework\TestCase;

final class PublicBlockParserAdapterTest extends TestCase
{
    public function testAdapterTranslatesAValidatedStartWithoutSharingState(): void
    {
        $state = $this->state("  :::note\n");
        $adapter = new PublicBlockParserAdapter(24, new AdapterFixtureParser());
        $start = $adapter->tryStart($state, 0, false);

        self::assertNotNull($start);
        self::assertSame(24, $start->kind);
        self::assertSame(2, $start->startOffset);
        self::assertSame(9, $start->contentOffset);
        self::assertTrue($start->isContainer);
        self::assertSame('note', $start->extensionState?->string('label'));
    }

    public function testAdapterRejectsAnOffsetOutsideTheCurrentLine(): void
    {
        $state = $this->state(":::\n");
        $adapter = new PublicBlockParserAdapter(24, new InvalidRangeParser());

        $this->expectException(InvalidBlockResultException::class);
        $this->expectExceptionMessage('Custom block range 0..4 must stay between cursor 0 and line content end 3.');

        $adapter->tryStart($state, 0, false);
    }

    public function testContextRejectsAByteOutsideTheCurrentLine(): void
    {
        $context = new ExtensionBlockContext($this->state(":::\n"), false);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Block extension byte offset 3 must stay within current line content [0, 3).');

        $context->byteAt(3);
    }

    public function testContextReadsAByteInsideTheCurrentLine(): void
    {
        self::assertSame(
            \ord(':'),
            new ExtensionBlockContext($this->state(":::\n"), false)->byteAt(0),
        );
    }

    public function testContextRejectsASliceOutsideTheCurrentLine(): void
    {
        $context = new ExtensionBlockContext($this->state(":::\n"), false);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Block extension slice 0..4 must stay within current line content [0, 3].');

        $context->slice(0, 4);
    }

    public function testContinuePersistsReturnedExtensionState(): void
    {
        $state = $this->state("body\n");
        $ordinal = $state->tape->allocate(24, ParseTape::NONE, 0, 0);
        $state->tape->setExtensionBlockState($ordinal, new BlockState(['label' => 'old']));
        $adapter = new PublicBlockParserAdapter(24, new StatefulContinueParser());

        $adapter->tryContinue($state, $ordinal);

        self::assertSame('new', $state->tape->extensionBlockState($ordinal)->string('label'));
    }

    public function testContinueRejectsClosedResultWithoutAdvanceOffset(): void
    {
        $state = $this->state("body\n");
        $ordinal = $state->tape->allocate(24, ParseTape::NONE, 0, 0);
        $state->tape->setExtensionBlockState($ordinal, new BlockState());

        $this->expectException(InvalidBlockResultException::class);
        $this->expectExceptionMessage('closed custom block must provide an advance offset');

        new PublicBlockParserAdapter(24, new ClosedWithoutAdvanceParser())
            ->tryContinue($state, $ordinal);
    }

    public function testContinueRejectsAdvancePastTheCurrentLine(): void
    {
        $state = $this->state("body\n");
        $ordinal = $state->tape->allocate(24, ParseTape::NONE, 0, 0);
        $state->tape->setExtensionBlockState($ordinal, new BlockState());

        $this->expectException(InvalidBlockResultException::class);
        $this->expectExceptionMessage('Custom block advance offset 5');

        new PublicBlockParserAdapter(24, new InvalidAdvanceParser())
            ->tryContinue($state, $ordinal);
    }

    private function state(string $source): ParserState
    {
        $buffer = new SourceBuffer($source);
        $scanner = new LineScanner($buffer);

        return new ParserState($buffer, $scanner, new LineMap($buffer, $scanner), new ParseTape());
    }
}

final class AdapterFixtureParser implements BlockParser
{
    public function triggerBytes(): string
    {
        return ':';
    }

    public function tryStart(BlockStartContext $context): BlockStartResult
    {
        return new BlockStartResult(
            $context->firstNonSpaceOffset(),
            $context->lineContentEndOffset(),
            container: true,
            state: new BlockState(['label' => 'note']),
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        return BlockContinueResult::matched(state: $context->state());
    }
}

class InvalidRangeParser implements BlockParser
{
    public function triggerBytes(): string
    {
        return ':';
    }

    public function tryStart(BlockStartContext $context): BlockStartResult
    {
        return new BlockStartResult(0, $context->lineContentEndOffset() + 1);
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        return BlockContinueResult::notMatched();
    }
}

final class StatefulContinueParser extends InvalidRangeParser
{
    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        return BlockContinueResult::matched(state: new BlockState(['label' => 'new']));
    }
}

final class ClosedWithoutAdvanceParser extends InvalidRangeParser
{
    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        $reflection = new \ReflectionClass(BlockContinueResult::class);
        $result = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'action' => \Alto\Markdown\Extension\Block\BlockContinueAction::Closed,
            'advanceOffset' => null,
            'state' => null,
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($result, $value);
        }

        return $result;
    }
}

final class InvalidAdvanceParser extends InvalidRangeParser
{
    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        return BlockContinueResult::matched($context->lineContentEndOffset() + 1);
    }
}
