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

use Alto\Markdown\Markdown;
use Alto\Markdown\Tests\Support\SemanticTreeComparator;
use PHPUnit\Framework\TestCase;

final class MarkdownGeneratedBuilderTest extends TestCase
{
    public function testGeneratedBuilderDocumentsRoundTrip(): void
    {
        mt_srand(20260707);

        $factory = Markdown::github();
        $comparator = new SemanticTreeComparator();

        for ($case = 0; $case < 1000; ++$case) {
            $builder = $factory->builder();
            $operations = mt_rand(1, 8);

            for ($operation = 0; $operation < $operations; ++$operation) {
                match (mt_rand(0, 6)) {
                    0 => $builder->h1($this->words()),
                    1 => $builder->h2($this->words()),
                    2 => $builder->paragraph($this->words().' | marker'),
                    3 => $builder->codeBlock('php', 'echo "'.$this->word()."\";\n"),
                    4 => $builder->unorderedList([$this->words(), $this->words()]),
                    5 => $builder->orderedList([$this->words(), $this->words()]),
                    6 => $builder->blockquote($this->words()),
                };
            }

            $document = $builder->document();
            $roundTripped = $factory->fromString($document->toMarkdown());
            $comparison = $comparator->compare($document->model(), $roundTripped->model());

            self::assertTrue($comparison->isEqual(), 'case '.$case.': '.$comparison->message());
        }
    }

    private function words(): string
    {
        $words = [];
        $count = mt_rand(1, 4);

        for ($i = 0; $i < $count; ++$i) {
            $words[] = $this->word();
        }

        return implode(' ', $words);
    }

    private function word(): string
    {
        $words = ['alpha', 'beta', 'gamma', 'delta', 'marker', 'pipe'];

        return $words[mt_rand(0, \count($words) - 1)];
    }
}
