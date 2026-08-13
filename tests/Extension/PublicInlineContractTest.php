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
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Extension\Inline\HtmlInlineOutputContext;
use Alto\Markdown\Extension\Inline\HtmlInlineRenderer;
use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\Inline\InlineLinkSemantics;
use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Extension\Inline\MarkdownInlinePrinter;
use Alto\Markdown\Extension\InlineExtensionInterface;
use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Profile\AbstractProfile;
use Alto\Markdown\Profile\Feature;
use Alto\Markdown\Profile\ProfileCompiler;
use Alto\Markdown\Tests\Extension\Fixture\PublicMarkOutput;
use Alto\Markdown\Tests\Extension\Fixture\PublicMarkParser;
use PHPUnit\Framework\TestCase;

final class PublicInlineContractTest extends TestCase
{
    public function testInlineDefinitionRejectsAnInvalidKindName(): void
    {
        $output = new PublicMarkOutput();

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Inline kind name "Not Valid"');

        new InlineDefinition('Not Valid', new PublicMarkParser(), $output, $output);
    }

    public function testInlineNodeReadsTypedImmutableAttributes(): void
    {
        $node = new InlineNode('Ada', [
            'enabled' => true,
            'index' => 3,
            'label' => 'person',
            'optional' => null,
        ]);

        self::assertSame('Ada', $node->text);
        self::assertTrue($node->attribute('enabled'));
        self::assertSame(3, $node->int('index'));
        self::assertSame('person', $node->string('label'));
        self::assertNull($node->attribute('optional'));
    }

    public function testInlineNodeRejectsANonStringAttributeName(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('attribute names must be strings');

        new \ReflectionClass(InlineNode::class)->newInstance('text', [0 => 'invalid']);
    }

    public function testInlineNodeRejectsANonScalarAttribute(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('attribute "invalid" must be a boolean, integer, string, or null');

        new \ReflectionClass(InlineNode::class)->newInstance('text', ['invalid' => []]);
    }

    public function testInlineNodeRejectsMissingAndWronglyTypedAttributes(): void
    {
        $node = new InlineNode('text', ['index' => '3']);

        try {
            $node->attribute('missing');
            self::fail('Missing inline attributes must fail.');
        } catch (InvalidMarkdownArgumentException $exception) {
            self::assertStringContainsString('is not defined', $exception->getMessage());
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('is not an integer');
        $node->int('index');
    }

    public function testInlineNodeRejectsANonStringAttributeRead(): void
    {
        $node = new InlineNode('text', ['label' => 3]);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('is not a string');

        $node->string('label');
    }

    public function testInlineLinkSemanticsExposeTypedDestinationAndOptionalTitle(): void
    {
        $link = new InlineLinkSemantics('url', 'title');

        self::assertSame(
            ['destination' => 'https://example.com', 'title' => null],
            $link->attributes(new InlineNode('Example', [
                'url' => 'https://example.com',
                'title' => null,
            ])),
        );
    }

    public function testInlineLinkSemanticsRejectInvalidConfigurationAndValues(): void
    {
        try {
            new InlineLinkSemantics('Not Valid');
            self::fail('An invalid destination attribute must fail.');
        } catch (InvalidMarkdownArgumentException $exception) {
            self::assertStringContainsString('Inline link attribute', $exception->getMessage());
        }

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('is not a string or null');

        new InlineLinkSemantics('url', 'title')->attributes(new InlineNode('Example', [
            'url' => 'https://example.com',
            'title' => 3,
        ]));
    }

    public function testCompilerRejectsDuplicateQualifiedKinds(): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('Duplicate node kind "duplicate:mark"');

        Markdown::commonmark()->with(new DuplicateInlineKindExtension());
    }

    public function testCompilerCallsTriggerByteExactlyOnce(): void
    {
        $parser = new CountingInlineParser('^');

        Markdown::commonmark()->with(new TriggerInlineExtension('counting', $parser));

        self::assertSame(1, $parser->calls);
    }

    public function testCompilerRejectsAnInvalidInlineDefinitionValue(): void
    {
        $extension = self::createStub(InlineExtensionInterface::class);
        $extension->method('name')->willReturn('invalid-inline-definition');
        $extension->method('inlines')->willReturn([new \stdClass()]);

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('inlines() must yield');

        Markdown::commonmark()->with($extension);
    }

    public function testProfileIgnoresExtensionsThatDoNotDeclareFeatures(): void
    {
        $profile = new class ('test', [new TriggerInlineExtension('no-features', new CountingInlineParser('^'))]) extends AbstractProfile {};

        self::assertFalse($profile->supports(Feature::Strikethrough));
    }

    /**
     * @return iterable<string, array{callable(): MarkdownFactory, string}>
     */
    public static function profilesWithReservedTriggers(): iterable
    {
        yield 'commonmark core trigger' => [Markdown::commonmark(...), '*'];
        yield 'gfm core trigger' => [Markdown::gfm(...), '['];
        yield 'github core trigger' => [Markdown::github(...), '!'];
        yield 'gfm strikethrough trigger' => [Markdown::gfm(...), '~'];
        yield 'github strikethrough trigger' => [Markdown::github(...), '~'];
    }

    /**
     * @param callable(): MarkdownFactory $profile
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('profilesWithReservedTriggers')]
    public function testCompilerRejectsTriggersReservedByTheActiveProfile(callable $profile, string $trigger): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('is reserved by the active Markdown profile');

        $profile()->with(new TriggerInlineExtension('reserved', new CountingInlineParser($trigger)));
    }

    public function testFeatureAddedAfterAnInlineExtensionStillReservesItsTrigger(): void
    {
        $profile = new class ('late-feature', [new TriggerInlineExtension('tilde', new CountingInlineParser('~')), new GfmExtension()]) extends AbstractProfile {};

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('trigger byte "~" is reserved');

        new ProfileCompiler()->compile($profile);
    }
}

final readonly class DuplicateInlineKindExtension implements InlineExtensionInterface
{
    public function name(): string
    {
        return 'duplicate';
    }

    public function inlines(): iterable
    {
        $output = new PublicMarkOutput();

        yield new InlineDefinition('mark', new PublicMarkParser(), $output, $output);
        yield new InlineDefinition('mark', new PublicMarkParser(), $output, $output);
    }
}

final readonly class TriggerInlineExtension implements InlineExtensionInterface
{
    public function __construct(
        private string $extensionName,
        private InlineParser $parser,
    ) {}

    public function name(): string
    {
        return $this->extensionName;
    }

    public function inlines(): iterable
    {
        $output = new TriggerInlineOutput();

        yield new InlineDefinition('value', $this->parser, $output, $output);
    }
}

final class CountingInlineParser implements InlineParser
{
    public int $calls = 0;

    public function __construct(private readonly string $trigger) {}

    public function triggerByte(): string
    {
        ++$this->calls;

        return $this->trigger;
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        return null;
    }
}

final readonly class TriggerInlineOutput implements HtmlInlineRenderer, MarkdownInlinePrinter
{
    public function render(HtmlInlineOutputContext $context): string
    {
        return $context->escapeText($context->node->text);
    }

    public function print(MarkdownInlineOutputContext $context): string
    {
        return $context->source();
    }
}
