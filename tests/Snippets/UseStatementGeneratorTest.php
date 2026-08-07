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

namespace Alto\Markdown\Tests\Snippets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class UseStatementGeneratorTest extends TestCase
{
    private const array MAP = [
        'MarkdownDocument' => 'Alto\Markdown\MarkdownDocument',
        'MarkdownFactory' => 'Alto\Markdown\MarkdownFactory',
        'DocumentStats' => 'Alto\Markdown\Stats\DocumentStats',
    ];

    public function testAddsUseForBareReference(): void
    {
        $code = "function f(MarkdownDocument \$doc): DocumentStats {\n    return \$doc->stats();\n}\n";

        self::assertSame(
            ['use Alto\Markdown\MarkdownDocument;', 'use Alto\Markdown\Stats\DocumentStats;'],
            $this->generate($code),
        );
    }

    public function testSkipsNamesNotReferenced(): void
    {
        $code = "\$factory = null;\n";

        self::assertSame([], $this->generate($code));
    }

    public function testSkipsWhenAlreadyImported(): void
    {
        $code = "use Alto\\Markdown\\MarkdownDocument;\n\nfunction f(MarkdownDocument \$doc): void {}\n";

        self::assertSame([], $this->generate($code));
    }

    public function testSkipsWhenImportedUnderAlias(): void
    {
        $code = "use Alto\\Markdown\\MarkdownDocument as Doc;\n\nfunction f(MarkdownDocument \$doc): void {}\n";

        self::assertSame([], $this->generate($code));
    }

    public function testSkipsFullyQualifiedReference(): void
    {
        $code = "function f(\\Alto\\Markdown\\MarkdownDocument \$doc): void {}\n";

        self::assertSame([], $this->generate($code));
    }

    public function testSortsGeneratedImports(): void
    {
        $code = "DocumentStats \$s; MarkdownFactory \$f; MarkdownDocument \$d;\n";

        self::assertSame(
            [
                'use Alto\Markdown\MarkdownDocument;',
                'use Alto\Markdown\MarkdownFactory;',
                'use Alto\Markdown\Stats\DocumentStats;',
            ],
            $this->generate($code),
        );
    }

    #[DataProvider('provideNonMatchingContexts')]
    public function testDoesNotMatchNonTypeContexts(string $code): void
    {
        self::assertSame([], $this->generate($code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonMatchingContexts(): iterable
    {
        yield 'method call' => ["\$obj->MarkdownDocument();\n"];
        yield 'static call' => ["Foo::MarkdownDocument();\n"];
        yield 'namespace prefix' => ["new MarkdownDocument\\Inner();\n"];
        yield 'longer identifier' => ["new MarkdownDocumentFactory();\n"];
        yield 'variable' => ["\$MarkdownDocument = 1;\n"];
    }

    public function testEachImportEmittedOnce(): void
    {
        $code = "MarkdownDocument \$a; MarkdownDocument \$b;\n";

        self::assertSame(['use Alto\Markdown\MarkdownDocument;'], $this->generate($code));
    }

    /**
     * @return list<string>
     */
    private function generate(string $code): array
    {
        return (new UseStatementGenerator())->generate($code, self::MAP);
    }
}
