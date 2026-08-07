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

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Block\BlockContinueAction;
use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockContinueResult;
use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\Block\BlockParser;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockStartResult;
use Alto\Markdown\Extension\Block\BlockState;
use PHPUnit\Framework\TestCase;

final class PublicBlockContractTest extends TestCase
{
    public function testCalloutUsesExactOffsetsAndOpaquePerBlockState(): void
    {
        $construct = new ContractCallout();
        $start = $construct->tryStart(new TestStartContext('  :::warning', 100, false));

        self::assertNotNull($start);
        self::assertSame(102, $start->startOffset);
        self::assertSame(112, $start->advanceOffset);
        self::assertTrue($start->container);
        self::assertSame(3, $start->state->int('fence'));
        self::assertSame('warning', $start->state->string('label'));

        $continue = $construct->tryContinue(new TestContinueContext('  :::', 200, $start->state));

        self::assertSame(BlockContinueAction::Closed, $continue->action);
        self::assertSame(205, $continue->advanceOffset);
    }

    public function testBodyLineKeepsTheContainerOpenWithoutConsumingChildren(): void
    {
        $state = new BlockState(['fence' => 3, 'label' => 'note']);
        $result = new ContractCallout()->tryContinue(new TestContinueContext('## Child', 40, $state));

        self::assertSame(BlockContinueAction::Matched, $result->action);
        self::assertNull($result->advanceOffset);
        self::assertSame($state, $result->state);
        self::assertSame(BlockContinueAction::NotMatched, BlockContinueResult::notMatched()->action);
    }

    public function testBlockDefinitionRejectsAnInvalidKindName(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Block kind name "Not Valid"');

        new BlockDefinition('Not Valid', new ContractCallout());
    }

    public function testBlockStateReadsValuesAndDerivesAnImmutableCopy(): void
    {
        $state = new BlockState([
            'enabled' => true,
            'fence' => 3,
            'label' => 'note',
            'optional' => null,
        ]);
        $changed = $state->with('label', 'warning');

        self::assertTrue($state->value('enabled'));
        self::assertSame(3, $state->int('fence'));
        self::assertSame('note', $state->string('label'));
        self::assertNull($state->value('optional'));
        self::assertSame('note', $state->string('label'));
        self::assertSame('warning', $changed->string('label'));
    }

    public function testBlockStateRejectsANonStringName(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Block state names must be strings.');

        new \ReflectionClass(BlockState::class)->newInstance([0 => 'invalid']);
    }

    public function testBlockStateRejectsANonScalarValue(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Block state value "invalid" must be scalar or null.');

        new \ReflectionClass(BlockState::class)->newInstance(['invalid' => []]);
    }

    public function testBlockStateRejectsAMissingValue(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Block state value "missing" is not defined.');

        new BlockState()->value('missing');
    }

    public function testBlockStateRejectsANonIntegerValue(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Block state value "fence" is not an integer.');

        new BlockState(['fence' => '3'])->int('fence');
    }

    public function testBlockStateRejectsANonStringValue(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Block state value "label" is not a string.');

        new BlockState(['label' => 3])->string('label');
    }
}

final class ContractCallout implements BlockParser
{
    public function triggerBytes(): string
    {
        return ':';
    }

    public function tryStart(BlockStartContext $context): ?BlockStartResult
    {
        $first = $context->firstNonSpaceOffset();
        $line = $context->slice($first, $context->lineContentEndOffset());

        if (1 !== preg_match('/^(:{3,})([A-Za-z][A-Za-z0-9-]*)$/', $line, $match)) {
            return null;
        }

        return new BlockStartResult(
            $first,
            $context->lineContentEndOffset(),
            container: true,
            state: new BlockState([
                'fence' => \strlen($match[1]),
                'label' => strtolower($match[2]),
            ]),
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        $first = $context->firstNonSpaceOffset();
        $line = $context->slice($first, $context->lineContentEndOffset());

        if (1 === preg_match('/^:+$/', $line) && \strlen($line) >= $context->state()->int('fence')) {
            return BlockContinueResult::closed($context->lineContentEndOffset());
        }

        return BlockContinueResult::matched(state: $context->state());
    }
}

class TestLineContext
{
    public function __construct(
        private readonly string $line,
        private readonly int $lineStart,
    ) {
    }

    public function lineStartOffset(): int
    {
        return $this->lineStart;
    }

    public function lineContentEndOffset(): int
    {
        return $this->lineStart + \strlen($this->line);
    }

    public function firstNonSpaceOffset(): int
    {
        return $this->lineStart + strspn($this->line, ' ');
    }

    public function indentColumns(): int
    {
        return strspn($this->line, ' ');
    }

    public function byteAt(int $offset): int
    {
        return \ord($this->line[$offset - $this->lineStart]);
    }

    public function slice(int $startOffset, int $endOffset): string
    {
        return substr($this->line, $startOffset - $this->lineStart, $endOffset - $startOffset);
    }
}

final class TestStartContext extends TestLineContext implements BlockStartContext
{
    public function __construct(string $line, int $lineStart, private readonly bool $paragraphOpen)
    {
        parent::__construct($line, $lineStart);
    }

    public function paragraphOpen(): bool
    {
        return $this->paragraphOpen;
    }
}

final class TestContinueContext extends TestLineContext implements BlockContinueContext
{
    public function __construct(string $line, int $lineStart, private readonly BlockState $state)
    {
        parent::__construct($line, $lineStart);
    }

    public function state(): BlockState
    {
        return $this->state;
    }
}
