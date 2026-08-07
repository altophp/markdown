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

namespace Alto\Markdown\Tests\Builder;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Tests\Support\SemanticTreeComparator;
use PHPUnit\Framework\TestCase;

final class MarkdownBuilderTest extends TestCase
{
    public function testDocumentBuilderRendersExpectedMarkdown(): void
    {
        $builder = Markdown::github()->builder()
            ->h1('Title')
            ->paragraph('Body.')
            ->codeBlock('php', "echo \"ok\";\n")
            ->unorderedList(['One', 'Two'])
            ->blockquote('Quote > here')
            ->thematicBreak();

        self::assertSame(
            "# Title\n\nBody\\.\n\n```php\necho \"ok\";\n```\n\n- One\n- Two\n\n> Quote \\> here\n\n---\n",
            $builder->toMarkdown(),
        );
    }

    public function testDocumentBuilderProducesParsedDocumentModel(): void
    {
        $factory = Markdown::github();
        $builder = $factory->builder()
            ->h2('Install')
            ->paragraph('Use [literal] brackets')
            ->orderedList(['First', 'Second']);

        $document = $builder->document();
        $expected = $factory->fromString($builder->toMarkdown());
        $comparison = new SemanticTreeComparator()->compare($expected->model(), $document->model());

        self::assertTrue($comparison->isEqual(), $comparison->message());
        self::assertSame($builder->toMarkdown(), $document->toMarkdown());
    }

    public function testFragmentBuilderPreservesCallOrder(): void
    {
        $fragment = Markdown::github()->fragment()
            ->raw('<!-- raw -->')
            ->paragraph('A | B')
            ->codeBlock(null, "```\n")
            ->toFragment();

        self::assertSame("<!-- raw -->\n\nA \\| B\n\n````\n```\n````\n", $fragment->toMarkdown());
    }

    public function testEmptyBuildersProduceEmptyContent(): void
    {
        self::assertSame('', Markdown::github()->builder()->toMarkdown());
        self::assertSame('', Markdown::github()->fragment()->toFragment()->toMarkdown());
    }

    public function testBuilderHandlesMultilineItemsAndFenceDelimiters(): void
    {
        self::assertSame(
            "- first\n\n  third\n",
            Markdown::github()->builder()->unorderedList(["first\n\nthird"])->toMarkdown(),
        );
        self::assertSame(
            "`````ph\\`p next\\\\\n````\n`````\n",
            Markdown::github()->builder()->codeBlock("ph`p\nnext\\", "````\n")->toMarkdown(),
        );
        self::assertSame(
            "```ph\\`p next\\\\\ncode\n```\n",
            Markdown::github()->fragment()->codeBlock("ph`p\nnext\\", 'code')->toFragment()->toMarkdown(),
        );
    }

    public function testBuilderRejectsAnInvalidHeadingLevel(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Heading level must be between 1 and 6.');

        Markdown::github()->builder()->heading(7, 'Invalid');
    }
}
