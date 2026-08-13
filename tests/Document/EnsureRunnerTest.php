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

use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownDocument;
use PHPUnit\Framework\TestCase;

final class EnsureRunnerTest extends TestCase
{
    public function testEnsureCreatesMissingSectionAtRequestedLevel(): void
    {
        $document = Markdown::github()->fromString("# Project\n");

        $returned = $document->ensure()->section('Changelog', 2)->apply();

        self::assertSame($document, $returned);
        self::assertSame('Changelog', $document->section('Changelog')->title());
        self::assertStringContainsString("## Changelog\n", $document->toMarkdown());
        self::assertSame('ensure section "Changelog"', $document->model()->journal()->operations()[0]->describe());
        self::assertSame(\strlen("# Project\n"), $document->model()->journal()->entries()[0]->affectedRange?->startOffset);
    }

    public function testEnsureDefaultsToLevelOneAndSupportsChaining(): void
    {
        $document = Markdown::github()->fromString('');

        $document->ensure()
            ->section('Intro')
            ->section('Usage', 2)
            ->apply();

        self::assertSame(['Intro', 'Usage'], self::headingTexts($document));
        self::assertStringContainsString("# Intro\n\n## Usage\n", $document->toMarkdown());
        self::assertCount(2, $document->model()->journal()->operations());
    }

    public function testEnsureLeavesExistingSectionUntouchedWithoutJournalEntry(): void
    {
        $document = Markdown::github()->fromString("# Project\n\n## Install\n\nKeep.\n");

        $document->ensure()->section('install', 3)->apply();

        self::assertSame(['Project', 'Install'], self::headingTexts($document));
        self::assertTrue($document->model()->journal()->isEmpty());
    }

    public function testSecondIdenticalEnsureAfterCleanJournalIsEmpty(): void
    {
        $document = Markdown::github()->fromString("# Project\n");

        $document->ensure()->section('Changelog', 2)->apply();
        $document->model()->journal()->clear();
        $document->ensure()->section('Changelog', 2)->apply();

        self::assertTrue($document->model()->journal()->isEmpty());
        self::assertSame(['Project', 'Changelog'], self::headingTexts($document));
    }

    public function testEnsureSeparatesASectionFromContentWithoutFinalNewline(): void
    {
        $document = Markdown::github()->fromString('Body');

        $document->ensure()->section('Next')->apply();

        self::assertSame("Body\n\n# Next\n", $document->toMarkdown());
    }

    /**
     * @return list<string>
     */
    private static function headingTexts(MarkdownDocument $document): array
    {
        return array_map(
            static fn(\Alto\Markdown\Node\Heading $heading): string => $heading->text(),
            $document->headings()->all(),
        );
    }
}
