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

namespace Alto\Markdown\Tests\Formatter;

use Alto\Markdown\Exception\InvalidFormatterResultException;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Exception\PatchConflictException;
use Alto\Markdown\Extension\Formatter\FormatterPass as ExtensionFormatterPass;
use Alto\Markdown\Extension\Formatter\FormatterPassDefinition;
use Alto\Markdown\Formatter\DocumentFormatRunner;
use Alto\Markdown\Formatter\ExtensionFormattingPass;
use Alto\Markdown\Formatter\FormatResult;
use Alto\Markdown\Formatter\FormatterPassRegistry;
use Alto\Markdown\Formatter\FormattingLevel;
use Alto\Markdown\Formatter\FormattingPass;
use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class FormatterPassRegistryTest extends TestCase
{
    private const array EXPECTED_IDS = [
        'no-trailing-spaces',
        'atx-heading-spacing',
        'fence-marker',
        'fence-info-spacing',
        'bullet-marker',
        'ordered-list-delimiter',
        'table-delimiter',
        'reference-definition-spacing',
        'blank-lines',
        'final-newline',
    ];

    public function testRegistryPublishesPassesInExecutionOrder(): void
    {
        $registry = new FormatterPassRegistry();
        $metadata = $registry->metadata();

        self::assertSame(self::EXPECTED_IDS, $registry->ids());
        self::assertSame(self::EXPECTED_IDS, array_column($metadata, 'id'));
        self::assertSame([
            FormattingLevel::Source,
            FormattingLevel::Block,
            FormattingLevel::Block,
            FormattingLevel::Block,
            FormattingLevel::Container,
            FormattingLevel::Container,
            FormattingLevel::Block,
            FormattingLevel::Block,
            FormattingLevel::Document,
            FormattingLevel::Source,
        ], array_column($metadata, 'level'));
        self::assertSame('normalizeHeadingSpacing', $registry->metadataFor('atx-heading-spacing')?->styleField);
        self::assertNull($registry->metadataFor('not-a-pass'));

        foreach ($registry->createAll() as $index => $pass) {
            self::assertSame($metadata[$index]->level, $pass->level());
        }
    }

    public function testMarkdownStyleRejectsIneffectiveMarkerValues(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Invalid bullet marker');

        new MarkdownStyle(bulletMarker: 'x');
    }

    public function testMarkdownStyleRejectsFenceRunsInsteadOfSilentlyIgnoringThem(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Invalid fence marker');

        new MarkdownStyle(fenceMarker: '```');
    }

    public function testMarkdownStyleRejectsInvalidOrderedListDelimiter(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Invalid ordered-list delimiter');

        new MarkdownStyle(orderedListDelimiter: ':');
    }

    public function testFormatterRejectsOverlappingPassPatchesBeforeJournaling(): void
    {
        $document = Markdown::github()->fromString("abcd\n");
        $wide = self::pass(0, 3, 'ABC', 'wide format');
        $overlap = self::pass(2, 4, 'CD', 'overlap format');
        $runner = new DocumentFormatRunner($document, new MarkdownStyle(), [$wide, $overlap]);

        try {
            $runner->apply();
            self::fail('Expected overlapping formatter patches to fail.');
        } catch (PatchConflictException $error) {
            self::assertStringContainsString('Overlapping fix operations "wide format" and "overlap format"', $error->getMessage());
            self::assertFalse($document->hasChanges());
        }
    }

    public function testRunnerExposesAndReplacesItsCurrentStyle(): void
    {
        $document = Markdown::commonmark()->fromString("Text\n");
        $initial = MarkdownStyle::commonmark();
        $replacement = MarkdownStyle::github();
        $runner = new DocumentFormatRunner($document, $initial, []);

        self::assertSame($initial, $runner->currentStyle());
        self::assertSame($runner, $runner->style($replacement));
        self::assertSame($replacement, $runner->currentStyle());
        self::assertSame($document, $runner->apply());
    }

    public function testExtensionFormattingPassHandlesEmptyDefinitions(): void
    {
        $pass = new ExtensionFormattingPass([]);

        self::assertSame(FormattingLevel::Document, $pass->level());
        self::assertTrue($pass->format(
            Markdown::commonmark()->fromString("Text\n")->model(),
            MarkdownStyle::commonmark(),
        )->isEmpty());
    }

    public function testExtensionFormattingPassRejectsInvalidYieldedValues(): void
    {
        $formatter = self::createStub(ExtensionFormatterPass::class);
        $formatter->method('format')->willReturn([new \stdClass()]);
        $definition = new FormatterPassDefinition(
            'invalid-result',
            'Returns an invalid edit.',
            static fn(): ExtensionFormatterPass => $formatter,
        );

        $this->expectException(InvalidFormatterResultException::class);
        $this->expectExceptionMessage('Custom formatter pass "invalid-result" must yield');

        new ExtensionFormattingPass(['invalid-result' => $definition])->format(
            Markdown::commonmark()->fromString("Text\n")->model(),
            MarkdownStyle::commonmark(),
        );
    }

    private static function pass(
        int $start,
        int $end,
        string $replacement,
        string $description,
    ): FormattingPass {
        return new class ($start, $end, $replacement, $description) implements FormattingPass {
            public function __construct(
                private readonly int $start,
                private readonly int $end,
                private readonly string $replacement,
                private readonly string $description,
            ) {}

            public function level(): FormattingLevel
            {
                return FormattingLevel::Source;
            }

            public function format(
                \Alto\Markdown\DocumentModel $model,
                MarkdownStyle $style,
            ): FormatResult {
                $range = new SourceRange($this->start, $this->end);

                return new FormatResult([
                    new SourcePatchOperation(new SourcePatch(
                        $range,
                        $this->replacement,
                        $range,
                        $this->description,
                    )),
                ]);
            }
        };
    }
}
