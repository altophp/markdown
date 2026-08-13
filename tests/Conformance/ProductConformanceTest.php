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

namespace Alto\Markdown\Tests\Conformance;

use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Conformance of the SHIPPED renderer against the official spec corpora.
 *
 * `bin/conformance.php` scores TestHtmlRenderer, which shares the parser but
 * has its own block renderer. That is a useful second opinion: when it passes
 * and the product fails, the parse is right and the emission is wrong. It is
 * not the product's score, and reporting it as such hid a real gap for a long
 * time. This test closes that: it measures what callers actually get, through
 * both lanes.
 *
 * The known-failure list is exact, not a threshold. A newly failing example
 * fails the test, and so does a newly passing one: fixing a defect is meant to
 * be a deliberate edit here, with the corresponding example removed. Every
 * entry must name the defect so the list can never become a silent allowlist.
 */
final class ProductConformanceTest extends TestCase
{
    /**
     * Spec examples the shipped renderer does not yet satisfy.
     *
     * @var array<string, array<int, string>>
     */
    private const array KNOWN_FAILURES = [
        'spec_tests.json' => [],
    ];

    /**
     * GFM reuses the CommonMark corpus plus its own extensions, so its
     * failures are keyed separately: the same defect surfaces at a different
     * example number.
     *
     * @var array<int, string>
     */
    private const array KNOWN_GFM_FAILURES = [
        // These three are NOT defects. cmark-gfm's spec.txt inherits the
        // base CommonMark "Autolinks" section verbatim, where a bare URL stays
        // literal, then its own extension section specifies the opposite. We
        // implement the extension, so we linkify and the stale examples fail.
        // Making them pass would mean breaking bare autolinks.
        616 => 'corpus is stale: the extension linkifies the inner URL, correctly',
        619 => 'corpus is stale: the extension linkifies a bare URL, correctly',
        620 => 'corpus is stale: the extension linkifies a bare email, correctly',
    ];

    #[DataProvider('provideCorpora')]
    public function testShippedRendererMatchesTheSpec(string $fixture, string $profile): void
    {
        $examples = self::loadExamples($fixture);
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());
        $factory = self::factory($profile);
        $failing = [];

        foreach ($examples as $example) {
            try {
                $rendered = $factory->toHtml($example['markdown'], renderOptions: $options);
            } catch (\Throwable $error) {
                $rendered = 'threw ' . $error::class;
            }

            if ($rendered !== $example['html']) {
                $failing[] = $example['example'];
            }
        }

        $known = array_keys(self::KNOWN_FAILURES[$fixture] ?? self::KNOWN_GFM_FAILURES);
        sort($known);

        self::assertSame($known, $failing, \sprintf(
            "Shipped %s conformance changed under the %s profile: %d of %d examples fail.\n" .
            "Unexpected failures: %s\nNewly passing (remove them from KNOWN_FAILURES): %s",
            $fixture,
            $profile,
            \count($failing),
            \count($examples),
            self::describe(array_diff($failing, $known)),
            self::describe(array_diff($known, $failing)),
        ));
    }

    /**
     * Both lanes must reach the same verdict. Otherwise a fix could land in
     * one lane only and the score would depend on which entry point a caller
     * happens to use.
     */
    #[DataProvider('provideCorpora')]
    public function testBothLanesAgreeWithTheSpec(string $fixture, string $profile): void
    {
        $examples = self::loadExamples($fixture);
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::spec());
        $factory = self::factory($profile);
        $divergent = [];

        foreach ($examples as $example) {
            $direct = $factory->toHtml($example['markdown'], renderOptions: $options) === $example['html'];
            $document = $factory->fromString($example['markdown'])->toHtml($options) === $example['html'];

            if ($direct !== $document) {
                $divergent[] = $example['example'];
            }
        }

        self::assertSame([], $divergent, \sprintf(
            'Examples where the direct and document lanes disagree with the spec differently: %s',
            implode(', ', $divergent),
        ));
    }

    /**
     * @param array<int, int> $examples
     */
    private static function describe(array $examples): string
    {
        return [] === $examples ? 'none' : implode(', ', $examples);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCorpora(): iterable
    {
        yield 'commonmark spec' => ['spec_tests.json', 'commonmark'];
        yield 'gfm spec' => ['gfm_tests.json', 'gfm'];
    }

    private static function factory(string $profile): MarkdownFactory
    {
        return 'commonmark' === $profile ? Markdown::commonmark() : Markdown::gfm();
    }

    /**
     * @return list<array{example: int, markdown: string, html: string}>
     */
    private static function loadExamples(string $fixture): array
    {
        $path = \dirname(__DIR__) . '/fixtures/' . $fixture;
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
            $html = \is_array($entry) ? ($entry['html'] ?? null) : null;

            if (!\is_int($example) || !\is_string($markdown) || !\is_string($html)) {
                self::fail(\sprintf('Fixture "%s" contains a malformed example.', $path));
            }

            $examples[] = ['example' => $example, 'markdown' => $markdown, 'html' => $html];
        }

        return $examples;
    }
}
