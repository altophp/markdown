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

namespace Alto\Markdown\Tests\Edit;

use Alto\Markdown\Markdown;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use Alto\Markdown\Operation\PatchLoweringResult;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Tests\Support\SemanticTreeComparator;
use Alto\Markdown\Tests\Support\SourceEditExpectation;
use Alto\Markdown\Tests\Support\SourceEditPropertyHarness;
use PHPUnit\Framework\TestCase;

final class ReadmeEditSuiteTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    public function testScriptedReadmeEditsPreserveExpectedSourceProperties(): void
    {
        $this->assertSavedEditProperties('tests/fixtures/readme-react.md', static function (\Alto\Markdown\MarkdownFile $file): void {
            $file->section('Installation')->replaceBody("Updated install instructions.\n");
        }, ['Updated install instructions.']);

        $this->assertSavedEditProperties('tests/fixtures/readme-laravel.md', static function (\Alto\Markdown\MarkdownFile $file): void {
            $file->section('Learning Laravel')->rename('Learning');
        }, ['## Learning']);

        $this->assertSavedEditProperties('tests/fixtures/readme-laravel.md', static function (\Alto\Markdown\MarkdownFile $file): void {
            $file->section('License')->append("Additional license note.\n");
        }, ['Additional license note.']);

        $this->assertSavedEditProperties('tests/fixtures/readme-laravel.md', static function (\Alto\Markdown\MarkdownFile $file): void {
            $file->ensure()->section('Changelog', 2)->apply();
        }, ['## Changelog']);

        $this->assertSavedEditProperties('tests/fixtures/readme-react.md', function (\Alto\Markdown\MarkdownFile $file): void {
            $block = $file->codeBlocks('jsx')->first();
            self::assertNotNull($block);
            $block->setLanguage('tsx');
        }, ['```tsx']);
    }

    public function testDeterministicEditFuzzOverTwentyMarkdownFiles(): void
    {
        $seed = 20260707;
        $files = $this->fuzzCorpus();
        self::assertCount(18, $files);

        foreach ($files as $index => $relativePath) {
            $path = $this->copyFixture($relativePath);
            $file = Markdown::github()->open($path);
            $operation = ($seed + $index) % 4;

            match ($operation) {
                0 => $file->ensure()->section('Fuzz ' . $index, 2)->apply(),
                1 => $this->renameFirstHeading($file, 'Fuzz Title ' . $index),
                2 => $this->appendToFirstExistingSection($file, "Fuzz body {$index}.\n"),
                default => $this->replaceFirstCodeBlockOrEnsure($file, $index),
            };

            $lowered = new DisjointPatchLowerer()->lower($file->model(), $file->model()->journal());
            $comparisonBeforeSave = new SemanticTreeComparator()->compare(
                $file->model(),
                Markdown::github()->fromString($lowered->bytes)->model(),
            );
            self::assertTrue($comparisonBeforeSave->isEqual(), $relativePath . ': ' . $comparisonBeforeSave->message());
            $file->save();
            $saved = (string) file_get_contents($path);

            self::assertSame($lowered->bytes, $saved, $relativePath . ' should save the lowered bytes.');
            self::assertTrue($file->model()->journal()->isEmpty(), $relativePath . ' should clear journal after save.');
        }
    }

    /**
     * @param callable(\Alto\Markdown\MarkdownFile): void $edit
     * @param list<string>                                $editedNeedles
     */
    private function assertSavedEditProperties(string $fixturePath, callable $edit, array $editedNeedles): void
    {
        $path = $this->copyFixture($fixturePath);
        $original = (string) file_get_contents($path);
        $file = Markdown::github()->open($path);

        $edit($file);
        $lowered = new DisjointPatchLowerer()->lower($file->model(), $file->model()->journal());
        $comparisonBeforeSave = new SemanticTreeComparator()->compare(
            $file->model(),
            Markdown::github()->fromString($lowered->bytes)->model(),
        );
        self::assertTrue($comparisonBeforeSave->isEqual(), $comparisonBeforeSave->message());
        $originalRanges = $this->originalRanges($lowered);
        $editedRanges = $this->editedRanges($lowered);
        $preview = $file->diff()->toUnifiedString();
        $file->save();
        $saved = (string) file_get_contents($path);

        self::assertSame($lowered->bytes, $saved);
        self::assertSame($preview, \Alto\Markdown\Operation\Diff::between($original, $saved)->toUnifiedString());
        self::assertTrue($file->model()->journal()->isEmpty());

        foreach ($editedNeedles as $needle) {
            self::assertStringContainsString($needle, $saved);
        }

        new SourceEditPropertyHarness()->assertProperties(new SourceEditExpectation(
            originalBytes: $original,
            editedBytes: $saved,
            expectedSemanticBytes: $lowered->bytes,
            originalTouchedRanges: $originalRanges,
            editedTouchedRanges: $editedRanges,
        ), Markdown::github());
    }

    /**
     * @return list<SourceRange>
     */
    private function originalRanges(PatchLoweringResult $result): array
    {
        return array_map(static fn(SourcePatch $patch): SourceRange => $patch->range, $result->patches);
    }

    /**
     * @return list<SourceRange>
     */
    private function editedRanges(PatchLoweringResult $result): array
    {
        $ranges = [];
        $delta = 0;

        foreach ($result->patches as $patch) {
            $start = $patch->range->startOffset + $delta;
            $end = $start + \strlen($patch->replacement);
            $ranges[] = new SourceRange($start, $end);
            $delta += \strlen($patch->replacement) - ($patch->range->endOffset - $patch->range->startOffset);
        }

        return $ranges;
    }

    private function renameFirstHeading(\Alto\Markdown\MarkdownFile $file, string $title): void
    {
        $heading = $file->headings()->first();

        if ($heading instanceof \Alto\Markdown\Node\Heading) {
            $heading->rename($title);

            return;
        }

        $file->ensure()->section($title, 1)->apply();
    }

    private function appendToFirstExistingSection(\Alto\Markdown\MarkdownFile $file, string $body): void
    {
        $heading = $file->headings()->first();

        if ($heading instanceof \Alto\Markdown\Node\Heading) {
            $file->section($heading->text())->append($body);

            return;
        }

        $file->ensure()->section('Fuzz Body', 2)->apply();
    }

    private function replaceFirstCodeBlockOrEnsure(\Alto\Markdown\MarkdownFile $file, int $index): void
    {
        $block = $file->codeBlocks()->first();

        if (null !== $block) {
            $block->replaceCode("echo \"fuzz {$index}\";\n");

            return;
        }

        $file->ensure()->section('Fuzz Code ' . $index, 2)->apply();
    }

    private function copyFixture(string $relativePath): string
    {
        $target = $this->tempPath();
        copy($this->projectPath($relativePath), $target);

        return $target;
    }

    private function projectPath(string $relativePath): string
    {
        return \dirname(__DIR__, 2) . '/' . $relativePath;
    }

    private function tempPath(): string
    {
        $path = \sys_get_temp_dir() . '/alto-markdown-readme-' . \bin2hex(\random_bytes(8)) . '.md';
        $this->paths[] = $path;

        return $path;
    }

    /**
     * @return list<string>
     */
    private function fuzzCorpus(): array
    {
        return [
            'tests/fixtures/readme-react.md',
            'tests/fixtures/readme-laravel.md',
            'tests/fixtures/readme-symfony.md',
            'README.md',
            'docs/index.md',
            'docs/installation.md',
            'docs/getting-started.md',
            'docs/conversion/html.md',
            'docs/conversion/markdown.md',
            'docs/documents/profiles.md',
            'docs/documents/queries.md',
            'docs/documents/statistics.md',
            'docs/documents/editing.md',
            'docs/quality/linting.md',
            'docs/quality/fixing.md',
            'docs/quality/formatting.md',
            'docs/extensions.md',
            'docs/security.md',
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
