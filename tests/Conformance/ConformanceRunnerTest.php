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

use PHPUnit\Framework\TestCase;

final class ConformanceRunnerTest extends TestCase
{
    public function testCountsPassesFailuresAndThrowsPerSection(): void
    {
        $result = (new ConformanceRunner(self::fakeRenderer()))->run(self::examples());

        self::assertSame(2, $result->passed);
        self::assertSame(4, $result->total);

        self::assertCount(2, $result->sections);

        self::assertSame('Alpha', $result->sections[0]->section);
        self::assertSame(1, $result->sections[0]->passed);
        self::assertSame(2, $result->sections[0]->total);

        self::assertSame('Beta', $result->sections[1]->section);
        self::assertSame(1, $result->sections[1]->passed);
        self::assertSame(2, $result->sections[1]->total);
    }

    public function testSectionFilterKeepsOnlyMatchingExamples(): void
    {
        $result = (new ConformanceRunner(self::fakeRenderer()))
            ->run(self::examples(), new ExampleFilter(section: 'Beta'));

        self::assertCount(1, $result->sections);
        self::assertSame('Beta', $result->sections[0]->section);
        self::assertSame(1, $result->sections[0]->passed);
        self::assertSame(2, $result->sections[0]->total);
        self::assertSame(2, $result->total);
    }

    public function testExampleFilterKeepsASingleExample(): void
    {
        $result = (new ConformanceRunner(self::fakeRenderer()))
            ->run(self::examples(), new ExampleFilter(example: 3));

        self::assertSame(1, $result->total);
        self::assertSame(0, $result->passed);
        self::assertCount(1, $result->sections);
        self::assertSame('Beta', $result->sections[0]->section);
    }

    public function testRendererResolverCanSwitchByExample(): void
    {
        $fallback = self::literalRenderer('fallback');
        $special = self::literalRenderer('special');

        $result = (new ConformanceRunner(
            $fallback,
            static fn(SpecExample $example): HtmlRenderer => 'Beta' === $example->section ? $special : $fallback,
        ))->run([
            new SpecExample(markdown: 'x', html: 'fallback:x', example: 1, section: 'Alpha', startLine: 1, endLine: 2),
            new SpecExample(markdown: 'x', html: 'special:x', example: 2, section: 'Beta', startLine: 3, endLine: 4),
        ]);

        self::assertSame(2, $result->passed);
        self::assertSame(2, $result->total);
    }

    public function testGfmRendererFlattensNestedStrongFixtures(): void
    {
        $gfm = new TestHtmlRenderer((new \Alto\Markdown\Profile\ProfileCompiler())->compile(new \Alto\Markdown\Profile\GfmProfile()));

        self::assertSame("<p><strong>foo</strong></p>\n", $gfm->render("****foo****\n"));
        self::assertSame("<p><em><strong>foo</strong></em></p>\n", $gfm->render("_____foo_____\n"));
        self::assertSame("<p><strong><strong>foo</strong></strong></p>\n", new TestHtmlRenderer()->render("****foo****\n"));
    }

    /**
     * A document holding nothing but a reference definition renders as the
     * empty string under every profile. The GFM renderer used to return a
     * newline here, to match a fixture our own extractor had corrupted by
     * appending "\n" even when an example had no HTML lines at all. Both the
     * extractor and that special case are gone; this pins the agreement.
     */
    public function testOnlyReferenceDefinitionRendersEmptyUnderEveryProfile(): void
    {
        $gfm = new TestHtmlRenderer((new \Alto\Markdown\Profile\ProfileCompiler())->compile(new \Alto\Markdown\Profile\GfmProfile()));

        self::assertSame('', $gfm->render("[foo]: /url\n"));
        self::assertSame('', new TestHtmlRenderer()->render("[foo]: /url\n"));
    }

    public function testEmptyInputProducesEmptyResult(): void
    {
        $result = (new ConformanceRunner(self::fakeRenderer()))->run([]);

        self::assertSame(0, $result->passed);
        self::assertSame(0, $result->total);
        self::assertSame([], $result->sections);
    }

    private static function fakeRenderer(): HtmlRenderer
    {
        return new class implements HtmlRenderer {
            public function render(string $markdown): string
            {
                return match ($markdown) {
                    'good' => '<p>good</p>',
                    'boom' => throw new \RuntimeException('renderer exploded'),
                    default => '<p>WRONG</p>',
                };
            }
        };
    }

    private static function literalRenderer(string $prefix): HtmlRenderer
    {
        return new class ($prefix) implements HtmlRenderer {
            public function __construct(private readonly string $prefix) {}

            public function render(string $markdown): string
            {
                return $this->prefix . ':' . $markdown;
            }
        };
    }

    /**
     * @return list<SpecExample>
     */
    private static function examples(): array
    {
        return [
            new SpecExample(markdown: 'good', html: '<p>good</p>', example: 1, section: 'Alpha', startLine: 1, endLine: 2),
            new SpecExample(markdown: 'bad', html: '<p>expected</p>', example: 2, section: 'Alpha', startLine: 3, endLine: 4),
            new SpecExample(markdown: 'boom', html: '<p>boom</p>', example: 3, section: 'Beta', startLine: 5, endLine: 6),
            new SpecExample(markdown: 'good', html: '<p>good</p>', example: 4, section: 'Beta', startLine: 7, endLine: 8),
        ];
    }
}
