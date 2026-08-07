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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;
use Alto\Markdown\Extension\InlineExtensionInterface;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Tests\Extension\Fixture\PublicMarkExtension;
use Alto\Markdown\Tests\Extension\Fixture\PublicMarkParser;
use PHPUnit\Framework\TestCase;

final class PublicInlineExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testPublicExtensionRendersTheSameHtmlThroughDirectAndDocumentLanes(): void
    {
        $source = "A **^^marked^^** value.\n";
        $expected = "<p>A <strong><mark data-label=\"marked\">marked</mark></strong> value.</p>\n";
        $factory = Markdown::commonmark()->with(new PublicMarkExtension());

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());
    }

    public function testPublicExtensionPrintsNormalizedMarkdown(): void
    {
        $source = "A ^^marked^^ value.\n";
        $document = Markdown::commonmark()
            ->with(new PublicMarkExtension())
            ->fromString($source);

        self::assertSame($source, $document->toMarkdown());
        self::assertSame("A ^^marked^^ value\\.\n", $document->toMarkdown(new RenderOptions()));
    }

    public function testCustomNodeKeepsSemanticPlainTextAndQualifiedKind(): void
    {
        $source = "# A ^^marked^^ title\n";
        $document = Markdown::commonmark()
            ->with(new PublicMarkExtension())
            ->fromString($source);
        $heading = $document->headings()->first();
        $model = $document->model();

        self::assertNotNull($heading);
        self::assertSame('A marked title', $heading->text());
        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        $events = [...$model->traversalInlineEvents($heading->id()->ordinal)];
        self::assertSame('example:mark', $events[1][1]);
        self::assertEquals(new SourceRange(4, 14), $events[1][2]);
    }

    public function testCustomNodeContributesSemanticImageAltText(): void
    {
        $source = "![^^marked^^](image.png)\n";
        $expected = "<p><img src=\"image.png\" alt=\"marked\" /></p>\n";
        $factory = Markdown::commonmark()->with(new PublicMarkExtension());

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());
    }

    public function testOutputContextEscapesValuesAndAppliesUrlPolicy(): void
    {
        $context = new HtmlInlineOutputContext(
            new InlineNode('"><script>', ['label' => '"><script>']),
            new SourceRange(2, 6),
            '^^^^',
            HtmlPolicy::safe(),
        );

        self::assertSame('^^^^', $context->source());
        self::assertSame('&quot;&gt;&lt;script&gt;', $context->escapeText($context->node->text));
        self::assertSame('&quot;&gt;&lt;script&gt;', $context->escapeAttribute($context->node->string('label')));
        self::assertSame('', $context->escapeUrl('javascript:alert(1)'));
        self::assertSame('https://example.com/?a=1&amp;b=2', $context->escapeUrl('https://example.com/?a=1&b=2'));
    }

    public function testCoreOnlyDirectConversionNeverLooksUpACustomInlineRenderer(): void
    {
        $factory = Markdown::commonmark();
        $source = "A **strong** value with ^ carets.\n";
        $expected = "<p>A <strong>strong</strong> value with ^ carets.</p>\n";
        Instrumentation::reset();

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());
        self::assertSame("A **strong** value with \\^ carets\\.\n", $factory->fromString($source)->toMarkdown(new RenderOptions()));
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);
    }

    public function testInstalledExtensionWithoutMatchingSyntaxDoesNotLookUpItsRenderer(): void
    {
        $factory = Markdown::commonmark()->with(new PublicMarkExtension());
        Instrumentation::reset();

        self::assertSame("<p>Plain text.</p>\n", $factory->toHtml("Plain text.\n"));
        self::assertSame("<p>Plain text.</p>\n", $factory->fromString("Plain text.\n")->toHtml());
        self::assertSame("Plain text\\.\n", $factory->fromString("Plain text.\n")->toMarkdown(new RenderOptions()));
        self::assertSame(0, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);
    }

    public function testDeclinedParserCostIsBoundedByMatchingTriggerOccurrences(): void
    {
        $factory = Markdown::commonmark()->with(new PublicMarkExtension());
        $source = "^ one ^ two ^.\n";
        $expected = "<p>^ one ^ two ^.</p>\n";

        Instrumentation::reset();
        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame(3, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);

        Instrumentation::reset();
        self::assertSame($expected, $factory->fromString($source)->toHtml());
        self::assertSame(3, Instrumentation::$extensionInlineParserAttempts);
        self::assertSame(0, Instrumentation::$extensionInlineRendererLookups);
    }

    public function testMultilineContainerMatchKeepsExactJoinedSourceInEveryOutputLane(): void
    {
        $source = "> A ^^first\n> second^^ value.\n";
        $factory = Markdown::commonmark()->with(new SourceAwareMarkExtension());
        $expectedHtml = "<blockquote>\n<p>A <mark>source=^^first\nsecond^^</mark> value.</p>\n</blockquote>\n";
        $document = $factory->fromString($source);

        self::assertSame($expectedHtml, $factory->toHtml($source));
        self::assertSame($expectedHtml, $document->toHtml());
        self::assertSame("> A ^^first\n> second^^ value\\.\n", $document->toMarkdown(new RenderOptions()));

        $model = $document->model();
        self::assertInstanceOf(ParsedDocumentModel::class, $model);
        self::assertSame('A first'."\n".'second value.', $model->plainText(2));

        $custom = array_values(array_filter(
            [...$model->traversalInlineEvents(2)],
            static fn (array $event): bool => 'source-aware:mark' === $event[1],
        ));
        self::assertCount(1, $custom);
        self::assertEquals(new SourceRange(4, 22), $custom[0][2]);
    }

    public function testPublicInlinePriorityUsesCoreThenExtensionRegistrationOrder(): void
    {
        $first = new PriorityInlineParser('^', true, 'first');
        $second = new PriorityInlineParser('^', true, 'second');
        $factory = Markdown::commonmark()->with(
            new PriorityInlineExtension('first', $first),
            new PriorityInlineExtension('second', $second),
        );

        self::assertSame("<p>Before <u>first</u> after.</p>\n", $factory->toHtml("Before ^ after.\n"));
        self::assertSame(1, $first->attempts);
        self::assertSame(0, $second->attempts);

        $declining = new PriorityInlineParser('^', false, 'declining');
        $matching = new PriorityInlineParser('^', true, 'matching');
        $fallback = Markdown::commonmark()->with(
            new PriorityInlineExtension('declining', $declining),
            new PriorityInlineExtension('matching', $matching),
        );

        self::assertSame("<p>Before <u>matching</u> after.</p>\n", $fallback->fromString("Before ^ after.\n")->toHtml());
        self::assertSame(1, $declining->attempts);
        self::assertSame(1, $matching->attempts);

        $lessThan = new PriorityInlineParser('<', true, 'custom');
        $coreFirst = Markdown::commonmark()->with(new PriorityInlineExtension('less-than', $lessThan));

        self::assertSame("<p>Before &lt;x&gt; after.</p>\n", $coreFirst->toHtml("Before <x> after.\n"));
        self::assertSame(0, $lessThan->attempts);
        self::assertSame("<p>Before <u>custom</u> after.</p>\n", $coreFirst->toHtml("Before < after.\n"));
        self::assertSame(1, $lessThan->attempts);
    }

    public function testOutputRangeIsOriginalForBlocksAndNullForDetachedTableCells(): void
    {
        $factory = Markdown::gfm()->with(new RangeAwareMarkExtension());
        $block = "# ^^marked^^\n";

        self::assertStringContainsString(
            '<mark data-range="2:12" data-source="^^marked^^">marked</mark>',
            $factory->toHtml($block),
        );
        self::assertStringContainsString(
            '<mark data-range="2:12" data-source="^^marked^^">marked</mark>',
            $factory->fromString($block)->toHtml(),
        );

        $table = "| Value |\n| --- |\n| ^^marked^^ |\n";
        $expected = '<mark data-range="detached" data-source="^^marked^^">marked</mark>';

        self::assertStringContainsString($expected, $factory->toHtml($table));
        self::assertStringContainsString($expected, $factory->fromString($table)->toHtml());
        self::assertSame($table, $factory->fromString($table)->toMarkdown(new RenderOptions()));
    }

    public function testMultilineCrLfRangeKeepsOriginalByteOffsets(): void
    {
        $factory = Markdown::commonmark()->with(new RangeAwareMarkExtension());
        $source = "> A ^^first\r\n> second^^ value.\r\n";
        $expected = '<mark data-range="4:23" data-source="^^first'
            ."\n"
            .'second^^">first'
            ."\n"
            .'second</mark>';

        self::assertStringContainsString($expected, $factory->toHtml($source));
        self::assertStringContainsString($expected, $factory->fromString($source)->toHtml());
    }

    public function testCustomHtmlPassesThroughTheFinalCuratedPolicy(): void
    {
        $factory = Markdown::commonmark()->with(new UnsafePublicMarkExtension());
        $options = new RenderOptions(htmlPolicy: HtmlPolicy::curated());
        $expected = "<p><mark>marked</mark></p>\n";

        self::assertSame($expected, $factory->toHtml("^^marked^^\n", renderOptions: $options));
        self::assertSame($expected, $factory->fromString("^^marked^^\n")->toHtml($options));
    }

    public function testSafePolicyTreatsExtensionRendererAsTrustedCode(): void
    {
        $factory = Markdown::commonmark()->with(new UnsafePublicMarkExtension());
        $expected = "<p><script>unsafe()</script><mark>marked</mark></p>\n";

        self::assertSame($expected, $factory->toHtml("^^marked^^\n"));
        self::assertSame($expected, $factory->fromString("^^marked^^\n")->toHtml());
    }

    public function testMarkdownOutputContextReadsCurrentEditedInlineSource(): void
    {
        $document = Markdown::commonmark()
            ->with(new UnsafePublicMarkExtension())
            ->fromString("# Old\n");
        $title = $document->title();

        self::assertNotNull($title);
        $title->rename('^^changed^^');

        self::assertSame("# ^^changed^^\n", $document->toMarkdown(new RenderOptions()));
    }
}

final readonly class UnsafePublicMarkExtension implements InlineExtensionInterface
{
    public function name(): string
    {
        return 'unsafe';
    }

    public function inlines(): iterable
    {
        $output = new UnsafePublicMarkOutput();

        yield new InlineDefinition('mark', new PublicMarkParser(), $output, $output);
    }
}

final readonly class UnsafePublicMarkOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        return '<script>unsafe()</script><mark>'.$context->escapeText($context->node->text).'</mark>';
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return $context->source();
    }
}

final readonly class SourceAwareMarkExtension implements InlineExtensionInterface
{
    public function name(): string
    {
        return 'source-aware';
    }

    public function inlines(): iterable
    {
        $output = new SourceAwareMarkOutput();

        yield new InlineDefinition('mark', new PublicMarkParser(), $output, $output);
    }
}

final readonly class SourceAwareMarkOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        return '<mark>source='.$context->escapeText($context->source()).'</mark>';
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        if (null === $context->range) {
            throw new \LogicException('Document Markdown printers require original source ranges.');
        }

        return $context->source();
    }
}

final readonly class RangeAwareMarkExtension implements InlineExtensionInterface
{
    public function name(): string
    {
        return 'range-aware';
    }

    public function inlines(): iterable
    {
        $output = new RangeAwareMarkOutput();

        yield new InlineDefinition('mark', new PublicMarkParser(), $output, $output);
    }
}

final readonly class RangeAwareMarkOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        $range = null === $context->range
            ? 'detached'
            : $context->range->startOffset.':'.$context->range->endOffset;

        return '<mark data-range="'.$range.'" data-source="'.$context->escapeAttribute($context->source()).'">'
            .$context->escapeText($context->node->text)
            .'</mark>';
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return $context->source();
    }
}

final readonly class PriorityInlineExtension implements InlineExtensionInterface
{
    public function __construct(
        private string $extensionName,
        private PriorityInlineParser $parser,
    ) {
    }

    public function name(): string
    {
        return $this->extensionName;
    }

    public function inlines(): iterable
    {
        $output = new PriorityInlineOutput();

        yield new InlineDefinition('token', $this->parser, $output, $output);
    }
}

final class PriorityInlineParser implements InlineParser
{
    public int $attempts = 0;

    public function __construct(
        private readonly string $trigger,
        private readonly bool $matches,
        private readonly string $text,
    ) {
    }

    public function triggerByte(): string
    {
        return $this->trigger;
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        ++$this->attempts;

        if (!$this->matches) {
            return null;
        }

        return new InlineParseResult(
            $context->offset() + 1,
            new InlineNode($this->text),
        );
    }
}

final readonly class PriorityInlineOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        return '<u>'.$context->escapeText($context->node->text).'</u>';
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return $context->source();
    }
}
