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

use Alto\Markdown\Exception\MissingSectionException;
use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Node\Section;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class SectionTest extends TestCase
{
    public function testTitleReturnsFirstLevelOneHeading(): void
    {
        $document = Markdown::github()->fromString("Intro\n\n# Project\n\n# Other\n");

        $title = $document->title();

        self::assertNotNull($title);
        self::assertSame('Project', $title->text());
    }

    public function testSectionRangeIncludesNestedHeadingsUntilSameOrHigherLevel(): void
    {
        $source = <<<'MD'
            # Project

            ## Install

            First install.

            ### Details

            Nested details.

            ## Usage

            Usage body.
            MD;
        $document = Markdown::github()->fromString($source);
        $install = $document->section('install');
        $usage = $document->section('Usage');

        self::assertTrue($install->exists());
        self::assertSame('section', $install->kind()->name);
        self::assertSame('Install', $install->title());
        self::assertSame(strpos($source, '## Install'), $install->range()->startOffset);
        self::assertSame(strpos($source, '## Usage'), $install->range()->endOffset);

        self::assertTrue($usage->exists());
        self::assertSame(strpos($source, '## Usage'), $usage->range()->startOffset);
        self::assertSame(\strlen($source), $usage->range()->endOffset);
    }

    public function testSectionsReturnsDuplicateTitlesInDocumentOrder(): void
    {
        $document = Markdown::github()->fromString(<<<'MD'
            # Project

            ## Install

            First.

            ## install

            Second.
            MD);

        $sections = $document->sections('INSTALL')->all();

        self::assertContainsOnlyInstancesOf(Section::class, $sections);
        self::assertSame(['Install', 'install'], \array_map(
            static fn (Section $section): string => $section->title(),
            $sections,
        ));
    }

    public function testMissingSectionUsesNullObject(): void
    {
        $document = Markdown::github()->fromString("# Project\n");
        $section = $document->section('Missing');

        self::assertFalse($section->exists());
        self::assertSame('', $section->title());
        self::assertSame(-1, $section->id()->ordinal);
        self::assertSame(-1, $section->id()->generation);
        self::assertSame('missing-section', $section->kind()->name);
        self::assertEquals(new SourceRange(0, 0), $section->range());
        self::assertSame($document, $section->remove());
        self::assertSame($document, $section->replaceBody('Ignored.'));
    }

    public function testMissingSectionRejectsRename(): void
    {
        $section = Markdown::github()->fromString("# Project\n")->section('Missing');

        $this->expectException(MissingSectionException::class);
        $this->expectExceptionMessage('Cannot rename a missing section.');

        $section->rename('Other');
    }

    public function testMissingSectionRejectsAppend(): void
    {
        $section = Markdown::github()->fromString("# Project\n")->section('Missing');

        $this->expectException(MissingSectionException::class);
        $this->expectExceptionMessage('Cannot append to a missing section.');

        $section->append('Content.');
    }

    public function testMissingSectionRejectsPrepend(): void
    {
        $section = Markdown::github()->fromString("# Project\n")->section('Missing');

        $this->expectException(MissingSectionException::class);
        $this->expectExceptionMessage('Cannot prepend to a missing section.');

        $section->prepend('Content.');
    }

    public function testRenameSectionReadsBackAndRecordsJournalRange(): void
    {
        $source = "# Project\n\n## Install\n\nBody.\n";
        $document = Markdown::github()->fromString($source);
        $section = $document->section('Install');

        $returned = $section->rename('Setup');

        self::assertSame($section, $returned);
        self::assertSame('Setup', $section->title());
        self::assertSame('Setup', $document->section('Setup')->title());
        self::assertFalse($document->section('Install')->exists());
        self::assertSame('rename section "Install" to "Setup"', $document->model()->journal()->operations()[0]->describe());
        $range = self::affectedRange($document, 0);
        $installOffset = self::offsetOf($source, '## Install');
        self::assertSame($installOffset, $range->startOffset);
        self::assertSame($installOffset + \strlen('## Install'), $range->endOffset);
    }

    public function testPrependAndAppendSectionBodyReadBackAndRecordJournal(): void
    {
        $source = "# Project\n\n## Install\n\nOld body.\n\n## Usage\n\nUse it.\n";
        $document = Markdown::github()->fromString($source);
        $section = $document->section('Install');

        $section
            ->prepend("Intro body.\n")
            ->append(Markdown::github()->fragment()->paragraph('Added body.')->toFragment());

        $markdown = $document->toMarkdown();

        self::assertStringContainsString("## Install\n\nIntro body.\n\nOld body.\n\nAdded body\\.\n\n## Usage", $markdown);
        self::assertSame('prepend to section "Install"', $document->model()->journal()->operations()[0]->describe());
        self::assertSame('append to section "Install"', $document->model()->journal()->operations()[1]->describe());
        self::assertSame(self::offsetOf($source, '## Install') + \strlen('## Install'), self::affectedRange($document, 0)->startOffset);
        self::assertSame(self::offsetOf($source, '## Usage'), self::affectedRange($document, 1)->startOffset);
    }

    public function testReplaceBodyReturnsDocumentAndLeavesHeadingInPlace(): void
    {
        $document = Markdown::github()->fromString("# Project\n\n## Install\n\nOld body.\n\n## Usage\n\nUse it.\n");
        $section = $document->section('Install');

        $returned = $section->replaceBody("New body.\n");

        self::assertSame($document, $returned);
        self::assertSame('Install', $document->section('Install')->title());
        self::assertStringContainsString("## Install\n\nNew body.\n\n## Usage", $document->toMarkdown());
        self::assertStringNotContainsString('Old body.', $document->toMarkdown());
        self::assertSame('replace body of section "Install"', $document->model()->journal()->operations()[0]->describe());
    }

    public function testRemoveSectionReturnsDocumentAndRemovesHeadingAndBody(): void
    {
        $document = Markdown::github()->fromString("# Project\n\n## Install\n\nOld body.\n\n## Usage\n\nUse it.\n");
        $section = $document->section('Install');

        $returned = $section->remove();

        self::assertSame($document, $returned);
        self::assertFalse($section->exists());
        self::assertFalse($document->section('Install')->exists());
        self::assertSame('Usage', $document->section('Usage')->title());
        self::assertStringNotContainsString('## Install', $document->toMarkdown());
        self::assertStringNotContainsString('Old body.', $document->toMarkdown());
        self::assertSame('remove section "Install"', $document->model()->journal()->operations()[0]->describe());
    }

    private static function affectedRange(MarkdownDocument $document, int $entry): SourceRange
    {
        $range = $document->model()->journal()->entries()[$entry]->affectedRange;
        self::assertNotNull($range);

        return $range;
    }

    private static function offsetOf(string $source, string $needle): int
    {
        $offset = strpos($source, $needle);
        self::assertIsInt($offset);

        return $offset;
    }
}
