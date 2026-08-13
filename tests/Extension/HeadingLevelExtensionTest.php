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

namespace Alto\Markdown\Tests\Extension;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\Document\DocumentTransform;
use Alto\Markdown\Extension\Document\DocumentTransformContext;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;
use Alto\Markdown\Extension\HeadingLevel\HeadingLevelExtension;
use Alto\Markdown\Extension\HeadingLevel\HeadingLevelPolicy;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Alto\Markdown\Extension\HeadingPermalink\HeadingPermalinkPolicy;
use Alto\Markdown\Extension\TableOfContents\TableOfContentsExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadingLevelExtensionTest extends TestCase
{
    public function testShiftRendersEveryHeadingWithoutChangingDocumentState(): void
    {
        $factory = Markdown::commonmark()->with(
            new HeadingLevelExtension(HeadingLevelPolicy::shift(1)),
        );
        $source = "# Title\n\nSection\n-------\n\n> ### Nested\n";
        $expected = "<h2>Title</h2>\n<h3>Section</h3>\n<blockquote>\n<h4>Nested</h4>\n</blockquote>\n";

        self::assertSame($expected, $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
        self::assertTrue($document->diff()->isEmpty());
    }

    public function testMapChangesOnlyDeclaredLevelsIncludingNestedHeadings(): void
    {
        $factory = Markdown::commonmark()->with(
            new HeadingLevelExtension(HeadingLevelPolicy::map([
                1 => 3,
                3 => 5,
            ])),
        );

        self::assertSame(
            "<h3>One</h3>\n"
            . "<h2>Two</h2>\n"
            . "<blockquote>\n"
            . "<h5>Three</h5>\n"
            . "</blockquote>\n",
            $factory->toHtml("# One\n\n## Two\n\n> ### Three\n"),
        );
    }

    /**
     * @return iterable<string, array{HeadingLevelPolicy, int}>
     */
    public static function effectiveLevelPolicies(): iterable
    {
        yield 'map' => [HeadingLevelPolicy::map([2 => 4]), 4];
        yield 'shift' => [HeadingLevelPolicy::shift(1), 3];
        yield 'callback' => [HeadingLevelPolicy::using(static fn(int $level): int => $level + 3), 5];
    }

    #[DataProvider('effectiveLevelPolicies')]
    public function testEveryStrategyReadsTheEffectiveLevelFromEarlierTransforms(
        HeadingLevelPolicy $policy,
        int $expected,
    ): void {
        $factory = Markdown::commonmark()->with(
            new PriorHeadingLevelExtension(),
            new HeadingLevelExtension($policy),
        );

        self::assertSame(
            \sprintf("<h%d>Current</h%d>\n", $expected, $expected),
            $factory->toHtml("# Current\n"),
        );
    }

    public function testCallbackCanKeepOneLevelAndChangeAnother(): void
    {
        $factory = Markdown::commonmark()->with(
            new HeadingLevelExtension(HeadingLevelPolicy::using(
                static fn(int $level): ?int => 2 === $level ? null : $level + 1,
            )),
        );

        self::assertSame(
            "<h2>One</h2>\n<h2>Two</h2>\n<h4>Three</h4>\n",
            $factory->toHtml("# One\n\n## Two\n\n### Three\n"),
        );
    }

    public function testInlineRenderingDoesNotInvokeTheDocumentTransform(): void
    {
        $calls = 0;
        $factory = Markdown::commonmark()->with(
            new HeadingLevelExtension(HeadingLevelPolicy::using(
                static function (int $level) use (&$calls): int {
                    ++$calls;

                    return $level;
                },
            )),
        );

        self::assertSame('<strong>Inline</strong>', $factory->toInlineHtml('**Inline**'));
        self::assertSame(0, $calls);
    }

    public function testHeadingPermalinkFilterReadsTheProjectedLevel(): void
    {
        $factory = Markdown::commonmark()->with(
            new HeadingPermalinkExtension(new HeadingPermalinkPolicy(minLevel: 2, maxLevel: 2)),
            new HeadingLevelExtension(HeadingLevelPolicy::shift(1)),
        );

        self::assertSame(
            '<h2><a id="content-title" href="#content-title" '
            . 'class="heading-permalink" aria-hidden="true" title="Permalink">¶</a>'
            . "Title</h2>\n",
            $factory->toHtml("# Title\n"),
        );
    }

    public function testNodeAndSectionRenderingUseTheSameRenderOnlyProjection(): void
    {
        $source = "# First\n\nBody.\n\n# Second\n\nNext.\n";
        $document = Markdown::commonmark()
            ->with(new HeadingLevelExtension(HeadingLevelPolicy::shift(1)))
            ->fromString($source);
        $headings = $document->headings()->all();
        $renderer = new HtmlDocumentRenderer();

        self::assertCount(2, $headings);
        self::assertSame("<h2>Second</h2>\n", $renderer->renderNode($document->model(), $headings[1]));
        self::assertSame(
            "<h2>First</h2>\n<p>Body.</p>\n",
            $renderer->renderSection($document->model(), $document->section('First')),
        );
        self::assertSame($source, $document->toMarkdown());
        self::assertTrue($document->diff()->isEmpty());
    }

    public function testHeadingLevelAlwaysRunsBeforeTableOfContents(): void
    {
        $headingLevels = new HeadingLevelExtension(HeadingLevelPolicy::shift(1));
        $tableOfContents = new TableOfContentsExtension();
        $source = "@toc {min: 2, max: 2}\n\n# Title\n";
        $expected = "<nav class=\"table-of-contents\" id=\"toc\">\n"
            . "<ul>\n"
            . "<li><a href=\"#title\">Title</a></li>\n"
            . "</ul>\n"
            . "</nav>\n"
            . "<h2 id=\"title\">Title</h2>\n";

        self::assertSame(
            $expected,
            Markdown::commonmark()->with($headingLevels, $tableOfContents)->toHtml($source),
        );
        self::assertSame(
            $expected,
            Markdown::commonmark()->with($tableOfContents, $headingLevels)->toHtml($source),
        );
    }

    public function testShiftOverflowFailsOnlyWhenAnAffectedHeadingIsRendered(): void
    {
        $factory = Markdown::commonmark()->with(
            new HeadingLevelExtension(HeadingLevelPolicy::shift(-1)),
        );

        self::assertSame("<p>No heading.</p>\n", $factory->toHtml("No heading.\n"));

        $source = "# Cannot shift\n";
        $document = $factory->fromString($source);

        try {
            $document->toHtml();
            self::fail('Expected the encountered heading overflow to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertSame(
                'Rendered heading level must be an integer between 1 and 6, got 0 for level 1.',
                $error->getMessage(),
            );
        }

        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
        self::assertTrue($document->diff()->isEmpty());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidShifts(): iterable
    {
        yield 'below bound' => [-6];
        yield 'above bound' => [6];
    }

    #[DataProvider('invalidShifts')]
    public function testRejectsShiftOutsideTheOnlyPotentiallyValidRange(int $offset): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        HeadingLevelPolicy::shift($offset);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidMaps(): iterable
    {
        yield 'key below one' => [[0 => 1]];
        yield 'key above six' => [[7 => 1]];
        yield 'non-integer key' => [['one' => 1]];
        yield 'value below one' => [[1 => 0]];
        yield 'value above six' => [[1 => 7]];
        yield 'non-integer value' => [[1 => '2']];
    }

    /**
     * @param array<array-key, mixed> $levels
     */
    #[DataProvider('invalidMaps')]
    public function testRejectsInvalidMapLevels(array $levels): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        (new \ReflectionMethod(HeadingLevelPolicy::class, 'map'))->invoke(null, $levels);
    }

    /**
     * @return iterable<string, array{\Closure(int): mixed, string}>
     */
    public static function invalidCallbackResults(): iterable
    {
        yield 'zero' => [static fn(int $level): int => 0, 'got 0 for level 1'];
        yield 'above six' => [static fn(int $level): int => 7, 'got 7 for level 1'];
        yield 'wrong type' => [static fn(int $level): string => (string) $level, "got '1' (string) for level 1"];
        yield 'float' => [static fn(int $level): float => $level + 0.5, 'got 1.5 for level 1'];
        yield 'boolean' => [static fn(int $level): bool => true, 'got true (bool) for level 1'];
        yield 'array' => [static fn(int $level): array => [], 'got array for level 1'];
    }

    #[DataProvider('invalidCallbackResults')]
    public function testRejectsInvalidCallbackResult(\Closure $callback, string $expected): void
    {
        $factory = Markdown::commonmark()->with(
            new HeadingLevelExtension(HeadingLevelPolicy::using($callback)),
        );

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage($expected);

        $factory->toHtml("# Invalid\n");
    }

    public function testCallbackExceptionsPropagateWithoutChangingTheDocument(): void
    {
        $source = "# Title\n";
        $document = Markdown::commonmark()
            ->with(new HeadingLevelExtension(HeadingLevelPolicy::using(
                static fn(int $level): never => throw new \RuntimeException('callback failed at ' . $level),
            )))
            ->fromString($source);

        try {
            $document->toHtml();
            self::fail('Expected the callback failure to propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('callback failed at 1', $error->getMessage());
        }

        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
    }

    public function testEmptyMapAndZeroShiftAreExplicitNoOps(): void
    {
        foreach ([HeadingLevelPolicy::map([]), HeadingLevelPolicy::shift(0)] as $policy) {
            self::assertSame(
                "<h1>Title</h1>\n",
                Markdown::commonmark()
                    ->with(new HeadingLevelExtension($policy))
                    ->toHtml("# Title\n"),
            );
        }
    }
}

final readonly class PriorHeadingLevelExtension implements DocumentTransformExtensionInterface
{
    public function name(): string
    {
        return 'prior-heading-level';
    }

    public function documentTransforms(): iterable
    {
        yield new DocumentTransformDefinition(
            'level',
            static fn(): PriorHeadingLevelTransform => new PriorHeadingLevelTransform(),
            -200,
        );
    }
}

final readonly class PriorHeadingLevelTransform implements DocumentTransform
{
    public function transform(DocumentTransformContext $context): void
    {
        foreach ($context->headings() as $heading) {
            $context->overrideHeadingLevel($heading, 2);
        }
    }
}
