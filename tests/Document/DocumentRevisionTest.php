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
use Alto\Markdown\Node\Heading;
use PHPUnit\Framework\TestCase;

final class DocumentRevisionTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    public function testHandleHeldAcrossSaveGoesStaleInsteadOfResolvingToAnotherNode(): void
    {
        $path = $this->writeTempFile("# One\n\n## Two\n\n## Three\n");
        $file = Markdown::github()->open($path);
        $third = $this->heading($file->headings()->all(), 2);

        self::assertSame('Three', $third->text());
        self::assertSame(3, $third->id()->ordinal);

        $file->section('One')->prepend("Intro.\n");
        $file->save();

        // The rebased tape hands ordinal 3 to the "Two" heading, so a handle
        // that survived the save would otherwise read another node's data.
        $rebased = $this->heading($file->headings()->all(), 1);

        self::assertSame(3, $rebased->id()->ordinal);
        self::assertSame('Two', $rebased->text());

        self::assertFalse($third->exists());
        $this->assertStale(static fn (): int => $third->level());
        $this->assertStale(static fn (): string => $third->kind()->name);
        $this->assertStale(static fn (): int => $third->range()->startOffset);
        $this->assertStale(static fn (): string => $third->text());
    }

    public function testHandleToAnUntouchedNodeAlsoGoesStaleAcrossSave(): void
    {
        $path = $this->writeTempFile("# One\n\n## Two\n");
        $file = Markdown::github()->open($path);
        $second = $this->heading($file->headings()->all(), 1);

        self::assertTrue($second->exists());

        $file->section('One')->rename('Uno');
        $file->save();

        self::assertSame("# Uno\n\n## Two\n", file_get_contents($path));
        self::assertFalse($second->exists());
    }

    public function testGenerationIsStableAcrossReadsAndAdvancesOnEditAndSave(): void
    {
        $path = $this->writeTempFile("# One\n\nBody.\n");
        $file = Markdown::github()->open($path);
        $model = $file->model();

        self::assertSame(0, $model->generation());

        $file->headings()->all();
        $file->toHtml();
        $file->stats();

        self::assertSame(0, $model->generation());

        $file->section('One')->rename('Uno');
        $afterEdit = $model->generation();

        self::assertGreaterThan(0, $afterEdit);
        self::assertSame($afterEdit, $model->generation());

        $file->save();

        self::assertGreaterThan($afterEdit, $model->generation());
    }

    public function testGenerationAdvancesForEveryMutationKind(): void
    {
        $document = Markdown::github()->fromString("# One\n\nBody.\n\n## Two\n\nMore.\n");
        $model = $document->model();
        $seen = [$model->generation()];

        $document->section('One')->rename('Uno');
        $seen[] = $model->generation();

        $document->section('Two')->prepend("Lead.\n");
        $seen[] = $model->generation();

        $document->section('Two')->append("Tail.\n");
        $seen[] = $model->generation();

        $document->section('Two')->replaceBody("Fresh.\n");
        $seen[] = $model->generation();

        $document->section('Two')->remove();
        $seen[] = $model->generation();

        self::assertSame($seen, array_values(array_unique($seen)));
        self::assertSame($seen, $this->sorted($seen));
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function assertStale(callable $read): void
    {
        try {
            $read();
            self::fail('Expected a StaleHandleException from a handle held across a save.');
        } catch (StaleHandleException $exception) {
            self::assertSame('Node ordinal 3 is stale.', $exception->getMessage());
        }
    }

    /**
     * @param list<mixed> $headings
     */
    private function heading(array $headings, int $index): Heading
    {
        $heading = $headings[$index] ?? null;

        self::assertInstanceOf(Heading::class, $heading);

        return $heading;
    }

    private function writeTempFile(string $bytes): string
    {
        $path = \sys_get_temp_dir().'/alto-markdown-revision-'.\bin2hex(\random_bytes(8)).'.md';
        $this->paths[] = $path;
        file_put_contents($path, $bytes);

        return $path;
    }
}
