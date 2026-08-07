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
use Alto\Markdown\Extension\Document\DocumentTransformBlock;
use Alto\Markdown\Extension\Document\DocumentTransformContext;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\Document\DocumentTransformHeading;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;
use Alto\Markdown\Extension\Html\HtmlDecoratorDefinition;
use Alto\Markdown\Extension\Html\HtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;
use Alto\Markdown\Extension\HtmlDecoratorExtensionInterface;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentTransformExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
        OrderingDocumentTransform::$calls = [];
        CountingDocumentTransform::$factories = 0;
        CountingDocumentTransform::$transforms = 0;
        CapturingHeadingDecorator::$levels = [];
        ViewCapturingDocumentTransform::$blocks = [];
        ViewCapturingDocumentTransform::$headings = [];
        ViewCapturingDocumentTransform::$blocksMemoized = false;
        ViewCapturingDocumentTransform::$headingsMemoized = false;
        ForeignHeadingDocumentTransform::$heading = null;
        ForeignHeadingDocumentTransform::$calls = 0;
        ThrowOnceDocumentTransform::$calls = 0;
    }

    public function testProjectsTheSameHeadingLevelsWithoutChangingDocumentSource(): void
    {
        $factory = Markdown::commonmark()->with(self::fixedLevelExtension(4));
        $source = "## One\n\nBody.\n\n## Two\n\nNext.\n";
        $expected = "<h4>One</h4>\n<p>Body.</p>\n<h4>Two</h4>\n<p>Next.</p>\n";

        self::assertSame($expected, $factory->toHtml($source));

        $document = $factory->fromString($source);
        self::assertSame($expected, $document->toHtml());
        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
        self::assertTrue($document->diff()->isEmpty());

        $heading = $document->headings()->first();
        self::assertNotNull($heading);
        self::assertSame(
            "<h4>One</h4>\n",
            new HtmlDocumentRenderer()->renderNode($document->model(), $heading),
        );
        self::assertSame(
            "<h4>One</h4>\n<p>Body.</p>\n",
            new HtmlDocumentRenderer()->renderSection(
                $document->model(),
                $document->section('One'),
            ),
        );
    }

    public function testOrdersTransformsStablyAndLetsTheLastOverrideWin(): void
    {
        $first = new FixtureDocumentTransformExtension('first-extension', [
            new DocumentTransformDefinition(
                'second',
                static fn (): OrderingDocumentTransform => new OrderingDocumentTransform('second', 3),
                order: 20,
            ),
            new DocumentTransformDefinition(
                'first',
                static fn (): OrderingDocumentTransform => new OrderingDocumentTransform('first', 2),
                order: 10,
            ),
        ]);
        $second = new FixtureDocumentTransformExtension('second-extension', [
            new DocumentTransformDefinition(
                'third',
                static fn (): OrderingDocumentTransform => new OrderingDocumentTransform('third', 5),
                order: 20,
            ),
        ]);

        $html = Markdown::commonmark()
            ->with($first, $second)
            ->toHtml("# Title\n");

        self::assertSame("<h5>Title</h5>\n", $html);
        self::assertSame(['first', 'second', 'third'], OrderingDocumentTransform::$calls);
    }

    public function testHeadingViewsAreIndependentLazyMemoizedAndNested(): void
    {
        $factory = Markdown::commonmark()->with(new FixtureDocumentTransformExtension('views', [
            new DocumentTransformDefinition(
                'headings',
                static fn (): ViewCapturingDocumentTransform => new ViewCapturingDocumentTransform(includeHeadings: true),
            ),
        ]));

        Instrumentation::reset();
        $factory->toHtml("> ## Nested\n>\n> Body.\n");

        self::assertSame(0, Instrumentation::$documentTransformBlockViews);
        self::assertSame(1, Instrumentation::$documentTransformHeadingViews);
        self::assertTrue(ViewCapturingDocumentTransform::$headingsMemoized);
        self::assertCount(1, ViewCapturingDocumentTransform::$headings);
        self::assertSame('atx-heading', ViewCapturingDocumentTransform::$headings[0]->kind);
        self::assertSame(1, ViewCapturingDocumentTransform::$headings[0]->depth);
        self::assertSame(2, ViewCapturingDocumentTransform::$headings[0]->level);
        self::assertSame(2, ViewCapturingDocumentTransform::$headings[0]->range->startOffset);
        self::assertSame(11, ViewCapturingDocumentTransform::$headings[0]->range->endOffset);
        self::assertSame(
            ['kind', 'range', 'depth', 'level'],
            array_keys(get_object_vars(ViewCapturingDocumentTransform::$headings[0])),
        );
    }

    public function testBlockViewsAreLazyAndMemoizedWithoutBuildingHeadings(): void
    {
        $factory = Markdown::commonmark()->with(new FixtureDocumentTransformExtension('views', [
            new DocumentTransformDefinition(
                'blocks',
                static fn (): ViewCapturingDocumentTransform => new ViewCapturingDocumentTransform(includeBlocks: true),
            ),
        ]));

        Instrumentation::reset();
        $factory->toHtml("> ## Nested\n>\n> Body.\n");

        self::assertSame(1, Instrumentation::$documentTransformBlockViews);
        self::assertSame(0, Instrumentation::$documentTransformHeadingViews);
        self::assertTrue(ViewCapturingDocumentTransform::$blocksMemoized);
        self::assertContainsOnlyInstancesOf(DocumentTransformBlock::class, ViewCapturingDocumentTransform::$blocks);
        self::assertSame(
            ['block-quote', 'atx-heading', 'paragraph'],
            array_map(
                static fn (DocumentTransformBlock $block): string => $block->kind,
                ViewCapturingDocumentTransform::$blocks,
            ),
        );
    }

    public function testViewsHandleEmptyAndSetextDocuments(): void
    {
        $factory = Markdown::commonmark()->with(new FixtureDocumentTransformExtension('views', [
            new DocumentTransformDefinition(
                'headings',
                static fn (): ViewCapturingDocumentTransform => new ViewCapturingDocumentTransform(includeHeadings: true),
            ),
        ]));

        self::assertSame('', $factory->toHtml(''));
        self::assertSame([], ViewCapturingDocumentTransform::$headings);

        self::assertSame("<h1>Title</h1>\n", $factory->toHtml("Title\n=====\n"));
        self::assertCount(1, ViewCapturingDocumentTransform::$headings);
        $heading = ViewCapturingDocumentTransform::firstHeading();
        self::assertInstanceOf(DocumentTransformHeading::class, $heading);
        self::assertSame('setext-heading', $heading->kind);
        self::assertSame(1, $heading->level);
    }

    public function testHeadingDecoratorReceivesTheProjectedLevel(): void
    {
        $factory = Markdown::commonmark()->with(
            self::fixedLevelExtension(5),
            new CapturingHeadingDecoratorExtension(),
        );

        $html = $factory->toHtml("## Projected\n");

        self::assertSame("<h5>Projected</h5>\n", $html);
        self::assertSame([5, 5], CapturingHeadingDecorator::$levels);
    }

    public function testTraversesDeepContainersWithoutNativeRecursion(): void
    {
        $depth = 550;
        $source = str_repeat('> ', $depth)."## Deep\n";
        $factory = Markdown::commonmark()->with(self::fixedLevelExtension(4));

        $html = $factory->toHtml(
            $source,
            new ParseOptions(maxNestingDepth: 600),
        );

        self::assertStringContainsString("<h4>Deep</h4>\n", $html);
        self::assertSame($depth, substr_count($html, '<blockquote>'));
    }

    public function testRebuildsThePlanAfterAnExplicitDocumentMutation(): void
    {
        $factory = Markdown::commonmark()->with(new FixtureDocumentTransformExtension('increment', [
            new DocumentTransformDefinition(
                'heading-level',
                static fn (): IncrementHeadingLevelDocumentTransform => new IncrementHeadingLevelDocumentTransform(),
            ),
        ]));
        $document = $factory->fromString("## Old\n");

        self::assertSame("<h3>Old</h3>\n", $document->toHtml());

        $heading = $document->headings()->first();
        self::assertNotNull($heading);
        $heading->replaceWith("#### New\n");

        self::assertSame("<h5>New</h5>\n", $document->toHtml());
        self::assertSame("#### New\n", $document->toMarkdown());
        self::assertTrue($document->hasChanges());
    }

    public function testCreatesOneFreshTransformPerRenderAndNoDirectWorkspace(): void
    {
        $factory = Markdown::commonmark()->with(new FixtureDocumentTransformExtension('counting', [
            new DocumentTransformDefinition(
                'count',
                static fn (): CountingDocumentTransform => CountingDocumentTransform::create(),
            ),
        ]));

        Instrumentation::reset();
        $factory->toHtml("# Direct\n");
        self::assertSame(0, Instrumentation::$documentWorkspaces);
        self::assertSame(1, Instrumentation::$documentTransformPlans);
        self::assertSame(1, Instrumentation::$documentTransformFactories);
        self::assertSame(1, Instrumentation::$documentTransformInvocations);

        $document = $factory->fromString("# Document\n");
        Instrumentation::reset();
        $document->toHtml();
        $document->toHtml();

        self::assertSame(3, CountingDocumentTransform::$factories);
        self::assertSame(3, CountingDocumentTransform::$transforms);
        self::assertSame(2, Instrumentation::$documentTransformPlans);
        self::assertSame(2, Instrumentation::$documentTransformFactories);
        self::assertSame(2, Instrumentation::$documentTransformInvocations);
        self::assertSame(2, Instrumentation::$documentTransformHeadingViews);
    }

    public function testRejectsAHeadingFromAnotherRenderContext(): void
    {
        $factory = Markdown::commonmark()->with(new FixtureDocumentTransformExtension('foreign', [
            new DocumentTransformDefinition(
                'heading',
                static fn (): ForeignHeadingDocumentTransform => new ForeignHeadingDocumentTransform(),
            ),
        ]));

        self::assertSame("<h1>First</h1>\n", $factory->toHtml("# First\n"));

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('current transform context');

        $factory->toHtml("# Second\n");
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidLevels(): iterable
    {
        yield 'zero' => [0];
        yield 'above six' => [7];
    }

    #[DataProvider('invalidLevels')]
    public function testRejectsInvalidProjectedHeadingLevels(int $level): void
    {
        $factory = Markdown::commonmark()->with(self::fixedLevelExtension($level));

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Rendered heading level must be between 1 and 6');

        $factory->toHtml("# Invalid\n");
    }

    public function testResetsThePlanBeforeAFormerlyFailingEntrypointRunsAgain(): void
    {
        $factory = Markdown::commonmark()->with(new FixtureDocumentTransformExtension('throw-once', [
            new DocumentTransformDefinition(
                'heading',
                static fn (): ThrowOnceDocumentTransform => new ThrowOnceDocumentTransform(),
            ),
        ]));

        try {
            $factory->toHtml("# First\n");
            self::fail('Expected the first transform to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('transform failed', $error->getMessage());
        }

        self::assertSame("<h2>Second</h2>\n", $factory->toHtml("# Second\n"));
    }

    public function testInactiveProfilesAndEmptyExtensionsBuildNoTransformState(): void
    {
        $factory = Markdown::commonmark()->with(new FixtureDocumentTransformExtension('empty', []));

        Instrumentation::reset();
        self::assertSame("<h1>Title</h1>\n", $factory->toHtml("# Title\n"));
        self::assertSame(0, Instrumentation::$documentTransformPlans);
        self::assertSame(0, Instrumentation::$documentTransformFactories);
        self::assertSame(0, Instrumentation::$documentTransformInvocations);
        self::assertSame(0, Instrumentation::$documentTransformBlockViews);
        self::assertSame(0, Instrumentation::$documentTransformHeadingViews);
        self::assertSame(0, Instrumentation::$documentWorkspaces);
    }

    public function testInlineConversionDoesNotRunDocumentTransforms(): void
    {
        $factory = Markdown::commonmark()->with(new FixtureDocumentTransformExtension('counting', [
            new DocumentTransformDefinition(
                'count',
                static fn (): CountingDocumentTransform => CountingDocumentTransform::create(),
            ),
        ]));

        Instrumentation::reset();
        self::assertSame('<strong>Inline</strong>', $factory->toInlineHtml('**Inline**'));
        self::assertSame(0, CountingDocumentTransform::$factories);
        self::assertSame(0, CountingDocumentTransform::$transforms);
        self::assertSame(0, Instrumentation::$documentTransformPlans);
        self::assertSame(0, Instrumentation::$documentTransformFactories);
        self::assertSame(0, Instrumentation::$documentTransformInvocations);
        self::assertSame(0, Instrumentation::$documentWorkspaces);
    }

    public function testDefinitionsValidateNamesFactoriesYieldsAndDuplicates(): void
    {
        try {
            new DocumentTransformDefinition(
                'Not Valid',
                static fn (): FixedHeadingLevelDocumentTransform => new FixedHeadingLevelDocumentTransform(2),
            );
            self::fail('Expected the invalid transform name to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertStringStartsWith('Document transform name "Not Valid"', $error->getMessage());
        }

        $invalidFactory = new \ReflectionClass(DocumentTransformDefinition::class)->newInstance(
            'invalid-factory',
            static fn (): object => new \stdClass(),
        );
        self::assertInstanceOf(DocumentTransformDefinition::class, $invalidFactory);

        try {
            Markdown::commonmark()
                ->with(new FixtureDocumentTransformExtension('invalid', [$invalidFactory]))
                ->toHtml("# Title\n");
            self::fail('Expected the invalid transform factory to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertStringContainsString('must return', $error->getMessage());
        }

        $invalidYield = self::createStub(DocumentTransformExtensionInterface::class);
        $invalidYield->method('name')->willReturn('invalid-yield');
        $invalidYield->method('documentTransforms')->willReturn([new \stdClass()]);

        try {
            Markdown::commonmark()->with($invalidYield);
            self::fail('Expected the invalid transform definition to fail.');
        } catch (InvalidMarkdownArgumentException $error) {
            self::assertStringContainsString('documentTransforms() must yield', $error->getMessage());
        }

        $duplicate = new FixtureDocumentTransformExtension('duplicate', [
            new DocumentTransformDefinition(
                'same',
                static fn (): FixedHeadingLevelDocumentTransform => new FixedHeadingLevelDocumentTransform(2),
            ),
            new DocumentTransformDefinition(
                'same',
                static fn (): FixedHeadingLevelDocumentTransform => new FixedHeadingLevelDocumentTransform(3),
            ),
        ]);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Duplicate document transform "duplicate:same".');

        Markdown::commonmark()->with($duplicate);
    }

    private static function fixedLevelExtension(int $level): FixtureDocumentTransformExtension
    {
        return new FixtureDocumentTransformExtension('fixed-level', [
            new DocumentTransformDefinition(
                'heading-level',
                static fn (): FixedHeadingLevelDocumentTransform => new FixedHeadingLevelDocumentTransform($level),
            ),
        ]);
    }
}

final readonly class FixtureDocumentTransformExtension implements DocumentTransformExtensionInterface
{
    /**
     * @param list<DocumentTransformDefinition> $definitions
     */
    public function __construct(
        private string $extensionName,
        private array $definitions,
    ) {
    }

    public function name(): string
    {
        return $this->extensionName;
    }

    public function documentTransforms(): iterable
    {
        yield from $this->definitions;
    }
}

final readonly class FixedHeadingLevelDocumentTransform implements DocumentTransform
{
    public function __construct(private int $level)
    {
    }

    public function transform(DocumentTransformContext $context): void
    {
        foreach ($context->headings() as $heading) {
            $context->overrideHeadingLevel($heading, $this->level);
        }
    }
}

final readonly class IncrementHeadingLevelDocumentTransform implements DocumentTransform
{
    public function transform(DocumentTransformContext $context): void
    {
        foreach ($context->headings() as $heading) {
            $context->overrideHeadingLevel($heading, min(6, $heading->level + 1));
        }
    }
}

final class OrderingDocumentTransform implements DocumentTransform
{
    /**
     * @var list<string>
     */
    public static array $calls = [];

    public function __construct(
        private string $name,
        private int $level,
    ) {
    }

    public function transform(DocumentTransformContext $context): void
    {
        self::$calls[] = $this->name;

        foreach ($context->headings() as $heading) {
            $context->overrideHeadingLevel($heading, $this->level);
        }
    }
}

final class ViewCapturingDocumentTransform implements DocumentTransform
{
    /**
     * @var list<DocumentTransformBlock>
     */
    public static array $blocks = [];

    /**
     * @var list<DocumentTransformHeading>
     */
    public static array $headings = [];

    public static bool $blocksMemoized = false;

    public static bool $headingsMemoized = false;

    public static function firstHeading(): ?DocumentTransformHeading
    {
        return self::$headings[0] ?? null;
    }

    public function __construct(
        private bool $includeBlocks = false,
        private bool $includeHeadings = false,
    ) {
    }

    public function transform(DocumentTransformContext $context): void
    {
        if ($this->includeBlocks) {
            self::$blocks = $context->blocks();
            self::$blocksMemoized = self::$blocks === $context->blocks();
        }

        if ($this->includeHeadings) {
            self::$headings = $context->headings();
            self::$headingsMemoized = self::$headings === $context->headings();
        }
    }
}

final class CountingDocumentTransform implements DocumentTransform
{
    public static int $factories = 0;

    public static int $transforms = 0;

    public static function create(): self
    {
        ++self::$factories;

        return new self();
    }

    public function transform(DocumentTransformContext $context): void
    {
        ++self::$transforms;
        $context->headings();
        $context->headings();
    }
}

final class ForeignHeadingDocumentTransform implements DocumentTransform
{
    public static ?DocumentTransformHeading $heading = null;

    public static int $calls = 0;

    public function transform(DocumentTransformContext $context): void
    {
        ++self::$calls;
        $current = $context->headings()[0] ?? null;

        if (1 === self::$calls) {
            self::$heading = $current;

            return;
        }

        $context->overrideHeadingLevel(self::saved(), 2);
    }

    private static function saved(): DocumentTransformHeading
    {
        if (!self::$heading instanceof DocumentTransformHeading) {
            throw new \LogicException('The fixture did not save a heading.');
        }

        return self::$heading;
    }
}

final class ThrowOnceDocumentTransform implements DocumentTransform
{
    public static int $calls = 0;

    public function transform(DocumentTransformContext $context): void
    {
        ++self::$calls;
        $heading = $context->headings()[0] ?? null;

        if (!$heading instanceof DocumentTransformHeading) {
            return;
        }

        $context->overrideHeadingLevel($heading, 1 === self::$calls ? 6 : 2);

        if (1 === self::$calls) {
            throw new \RuntimeException('transform failed');
        }
    }
}

final readonly class CapturingHeadingDecoratorExtension implements HtmlDecoratorExtensionInterface
{
    public function name(): string
    {
        return 'capture-heading-level';
    }

    public function htmlDecorators(): iterable
    {
        yield HtmlDecoratorDefinition::heading(new CapturingHeadingDecorator());
        yield HtmlDecoratorDefinition::node('atx-heading', new CapturingHeadingDecorator());
    }
}

final class CapturingHeadingDecorator implements HtmlNodeDecorator
{
    /**
     * @var list<int>
     */
    public static array $levels = [];

    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        self::$levels[] = $context->int('level');

        return $html;
    }
}
