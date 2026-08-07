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

namespace Alto\Markdown\Tests;

use Alto\Markdown\Builder\MarkdownBuilder;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\MarkdownExceptionInterface;
use Alto\Markdown\Lint\Linter;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Query\MarkdownQuery;
use Alto\Markdown\Render\HtmlRenderer;
use Alto\Markdown\Render\Renderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicApiTest extends TestCase
{
    /**
     * @param class-string $type
     */
    #[DataProvider('providePublicApiTypes')]
    public function testPublicApiTypeIsAnInterface(string $type): void
    {
        self::assertTrue((new \ReflectionClass($type))->isInterface());
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function providePublicApiTypes(): iterable
    {
        $types = [
            MarkdownFactory::class,
            MarkdownDocument::class,
            DocumentModel::class,
            MarkdownExceptionInterface::class,
            NodeHandle::class,
            MarkdownQuery::class,
            MarkdownBuilder::class,
            HtmlRenderer::class,
            Renderer::class,
        ];

        foreach ($types as $type) {
            yield $type => [$type];
        }
    }

    public function testLinterIsAPublicFinalService(): void
    {
        self::assertTrue((new \ReflectionClass(Linter::class))->isFinal());
    }
}
