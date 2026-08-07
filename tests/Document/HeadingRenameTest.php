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

namespace Alto\Markdown\Tests\Document;

use Alto\Markdown\Exception\StaleHandleException;
use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use PHPUnit\Framework\TestCase;

final class HeadingRenameTest extends TestCase
{
    public function testRenameReturnsTheSameHandleAndReadsBack(): void
    {
        $document = Markdown::github()->fromString("# Project\n\n## Install\n\nBody.\n");
        $heading = $this->heading($document, 1);

        $returned = $heading->rename('Setup');

        self::assertSame($heading, $returned);
        self::assertSame('Setup', $heading->text());
        self::assertSame(2, $heading->level());
        self::assertTrue($heading->exists());
        self::assertSame('Setup', $this->heading($document, 1)->text());
    }

    public function testRenameRebuildsTheAtxLineAndPatchesTheHeadingOnly(): void
    {
        $source = "# Project\n\n## Install\n\nBody.\n";
        $document = Markdown::github()->fromString($source);

        $this->heading($document, 1)->rename('Setup');

        self::assertSame("# Project\n\n## Setup\n\nBody.\n", $this->lowered($document));

        $operations = $document->model()->journal()->operations();

        self::assertCount(1, $operations);
        self::assertSame('rename heading "Install" to "Setup"', $operations[0]->describe());
    }

    public function testRenameDropsAClosingHashRun(): void
    {
        $document = Markdown::github()->fromString("## Install ##\n\nBody.\n");

        $this->heading($document, 0)->rename('Setup');

        self::assertSame("## Setup\n\nBody.\n", $this->lowered($document));
    }

    public function testRenameKeepsTheSetextUnderline(): void
    {
        $document = Markdown::github()->fromString("Install\n-------\n\nBody.\n");
        $heading = $this->heading($document, 0);

        self::assertSame(2, $heading->level());

        $heading->rename('Setup');

        self::assertSame('Setup', $heading->text());
        self::assertSame("Setup\n-------\n\nBody.\n", $this->lowered($document));
    }

    public function testRenameKeepsTheSetextUnderlineOnCrLfSource(): void
    {
        $document = Markdown::github()->fromString("Install\r\n=======\r\n\r\nBody.\r\n");

        $this->heading($document, 0)->rename('Setup');

        self::assertSame("Setup\r\n=======\r\n\r\nBody.\r\n", $this->lowered($document));
    }

    public function testRenameKeepsTheSetextUnderlineOnCrSource(): void
    {
        $document = Markdown::github()->fromString("Install\r-------\r\rBody.\r");

        $this->heading($document, 0)->rename('Setup');

        self::assertSame("Setup\r-------\r\rBody.\r", $this->lowered($document));
    }

    public function testRenameToAnEmptyTitleLeavesAnEmptyHeading(): void
    {
        $document = Markdown::github()->fromString("## Install\n\nBody.\n");
        $heading = $this->heading($document, 0);

        $heading->rename('');

        self::assertSame('', $heading->text());
        self::assertSame(2, $heading->level());
        self::assertSame("## \n\nBody.\n", $this->lowered($document));
    }

    /**
     * Markdown-significant characters are written through verbatim, exactly as
     * Section::rename() writes them, so the title becomes markup rather than
     * literal text. text() therefore reads back the flattened plain text.
     */
    public function testRenameWritesMarkdownSignificantCharactersVerbatim(): void
    {
        $document = Markdown::github()->fromString("## Install\n\nBody.\n");
        $heading = $this->heading($document, 0);

        $heading->rename('*starred* and `code`');

        self::assertSame('starred and code', $heading->text());
        self::assertSame("## *starred* and `code`\n\nBody.\n", $this->lowered($document));
    }

    public function testRenameOfAHeadingAndItsSectionAgreeOnTheResult(): void
    {
        $source = "# Project\n\n## Install ##\n\nBody.\n";
        $viaHeading = Markdown::github()->fromString($source);
        $viaSection = Markdown::github()->fromString($source);

        $this->heading($viaHeading, 1)->rename('Setup');
        $viaSection->section('Install')->rename('Setup');

        self::assertSame($this->lowered($viaSection), $this->lowered($viaHeading));
    }

    public function testRenameOnAStaleHeadingThrows(): void
    {
        $document = Markdown::github()->fromString("# Project\n\n## Install\n");
        $heading = $this->heading($document, 1);

        $document->section('Install')->remove();

        self::assertFalse($heading->exists());

        $this->expectException(StaleHandleException::class);
        $this->expectExceptionMessage('Node ordinal 2 is stale.');

        $heading->rename('Setup');
    }

    private function lowered(MarkdownDocument $document): string
    {
        $model = $document->model();

        return new DisjointPatchLowerer()->lower($model, $model->journal())->bytes;
    }

    private function heading(MarkdownDocument $document, int $index): Heading
    {
        $heading = $document->headings()->all()[$index] ?? null;

        self::assertInstanceOf(Heading::class, $heading);

        return $heading;
    }
}
