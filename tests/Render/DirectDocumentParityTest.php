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

namespace Alto\Markdown\Tests\Render;

use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Differential gate for the fused direct lane: every CommonMark and GFM
 * spec example renders through MarkdownFactory::toHtml() (fused inline
 * emission) and MarkdownDocument::toHtml() (inline tape walk) and the two
 * products must be byte-identical. Product versus product, not versus the
 * spec oracle: divergence from the oracle is the conformance runner's
 * concern; divergence between the lanes is a fused-emitter bug. Each
 * corpus runs under both the commonmark and gfm profiles to cover both
 * delimiter rule sets.
 */
final class DirectDocumentParityTest extends TestCase
{
    #[DataProvider('provideCorpusProfiles')]
    public function testDirectMatchesDocumentRendering(string $fixture, string $profile): void
    {
        $examples = self::loadExamples($fixture);
        $mismatches = [];

        // Spec policy is pinned so the two lanes are compared under
        // CommonMark passthrough, matching the conformance corpora the
        // fixtures were captured against.
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());

        foreach ($examples as $example) {
            $factory = self::factory($profile);
            $markdown = $example['markdown'];
            $direct = $factory->toHtml($markdown, renderOptions: $options);
            $document = $factory->fromString($markdown)->toHtml($options);

            if ($direct !== $document) {
                $mismatches[] = \sprintf(
                    "example %d:\nmarkdown: %s\ndirect:   %s\ndocument: %s",
                    $example['example'],
                    var_export($markdown, true),
                    var_export($direct, true),
                    var_export($document, true),
                );
            }
        }

        self::assertSame([], $mismatches, \sprintf(
            '%d of %d %s examples diverge between the direct and document lanes under the %s profile.',
            \count($mismatches),
            \count($examples),
            $fixture,
            $profile,
        ));
        self::assertGreaterThan(600, \count($examples));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCorpusProfiles(): iterable
    {
        foreach (['spec_tests.json', 'gfm_tests.json'] as $fixture) {
            foreach (['commonmark', 'gfm'] as $profile) {
                yield $fixture.' / '.$profile => [$fixture, $profile];
            }
        }
    }

    private static function factory(string $profile): MarkdownFactory
    {
        return match ($profile) {
            'commonmark' => Markdown::commonmark(),
            default => Markdown::gfm(),
        };
    }

    /**
     * @return list<array{example: int, markdown: string}>
     */
    private static function loadExamples(string $fixture): array
    {
        $path = \dirname(__DIR__).'/fixtures/'.$fixture;
        $json = file_get_contents($path);

        if (false === $json) {
            self::fail(\sprintf('Cannot read fixture "%s".', $path));
        }

        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            self::fail(\sprintf('Fixture "%s" is not an example list.', $path));
        }

        $examples = [];

        foreach ($decoded as $entry) {
            $example = \is_array($entry) ? ($entry['example'] ?? null) : null;
            $markdown = \is_array($entry) ? ($entry['markdown'] ?? null) : null;

            if (!\is_int($example) || !\is_string($markdown)) {
                self::fail(\sprintf('Fixture "%s" contains a malformed example.', $path));
            }

            $examples[] = ['example' => $example, 'markdown' => $markdown];
        }

        return $examples;
    }
}
