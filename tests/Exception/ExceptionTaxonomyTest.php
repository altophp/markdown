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

namespace Alto\Markdown\Tests\Exception;

use Alto\Markdown\Exception\DuplicateExtensionException;
use Alto\Markdown\Exception\FileConflictException;
use Alto\Markdown\Exception\FileException;
use Alto\Markdown\Exception\FileReadException;
use Alto\Markdown\Exception\FileWriteException;
use Alto\Markdown\Exception\InvalidBlockResultException;
use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Exception\InvalidFormatterResultException;
use Alto\Markdown\Exception\InvalidInlineResultException;
use Alto\Markdown\Exception\InvalidLintResultException;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\InvalidStatsResultException;
use Alto\Markdown\Exception\MarkdownExceptionInterface;
use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\Block\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionTaxonomyTest extends TestCase
{
    /**
     * @return iterable<string, array{FileException}>
     */
    public static function fileFailures(): iterable
    {
        yield FileReadException::class => [new FileReadException('read failed', 'input.md')];
        yield FileWriteException::class => [new FileWriteException('write failed', 'output.md')];
        yield FileConflictException::class => [new FileConflictException('shared.md', 'changed')];
    }

    #[DataProvider('fileFailures')]
    public function testFileFailuresShareAPathAwareRecoveryBoundary(FileException $exception): void
    {
        self::assertInstanceOf(MarkdownExceptionInterface::class, $exception);
        self::assertNotSame('', $exception->path);
    }

    public function testLegacyReadAndWriteConstructionRemainsValid(): void
    {
        self::assertSame('', (new FileReadException('read failed'))->path);
        self::assertSame('', (new FileWriteException('write failed'))->path);
    }

    /**
     * @return iterable<string, array{InvalidExtensionException}>
     */
    public static function invalidExtensionFailures(): iterable
    {
        yield DuplicateExtensionException::class => [new DuplicateExtensionException('duplicate')];
        yield InvalidBlockResultException::class => [new InvalidBlockResultException('block')];
        yield InvalidInlineResultException::class => [new InvalidInlineResultException('inline')];
        yield InvalidLintResultException::class => [new InvalidLintResultException('lint')];
        yield InvalidFormatterResultException::class => [new InvalidFormatterResultException('formatter')];
        yield InvalidStatsResultException::class => [new InvalidStatsResultException('stats')];
    }

    #[DataProvider('invalidExtensionFailures')]
    public function testInvalidExtensionsRemainInvalidArguments(InvalidExtensionException $exception): void
    {
        self::assertInstanceOf(InvalidMarkdownArgumentException::class, $exception);
        self::assertInstanceOf(MarkdownExceptionInterface::class, $exception);
    }

    public function testInvalidPublicDefinitionUsesTheExtensionBoundary(): void
    {
        $this->expectException(InvalidExtensionException::class);

        new BlockDefinition('INVALID', self::createStub(BlockParser::class));
    }
}
