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

namespace Alto\Markdown\Tests\Profile;

use Alto\Markdown\Extension\AbstractExtension;
use Alto\Markdown\Extension\CommonMark\CoreExtension;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\NodeKindExtensionInterface;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Parser\Block\BlockConstruct;
use Alto\Markdown\Parser\Block\BlockStart;
use Alto\Markdown\Parser\Block\ContinueResult;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Profile\AbstractProfile;
use Alto\Markdown\Profile\CommonMarkProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use PHPUnit\Framework\TestCase;

final class ThirdPartyExtensionDemoTest extends TestCase
{
    public function testDemoExtensionParsesAndRendersThroughRegistration(): void
    {
        $compiled = new ProfileCompiler()->compile(new DemoDirectiveProfile());
        $extension = $compiled->extensions()[1];

        self::assertInstanceOf(NodeKindExtensionInterface::class, $extension);

        $renderer = new DemoDirectiveRenderer($extension->nodeKinds());

        self::assertSame(
            '<directive name="alpha"></directive>'."\n",
            $renderer->render("::demo alpha\n", $compiled),
        );
    }

    public function testDemoNodeKindIdsAreDeterministicAcrossCompiles(): void
    {
        $first = new ProfileCompiler()->compile(new DemoDirectiveProfile());
        $second = new ProfileCompiler()->compile(new DemoDirectiveProfile());

        self::assertSame('demo:directive', $first->nodeKinds->get(24)->name);
        self::assertSame('demo:directive', $second->nodeKinds->get(24)->name);
        self::assertSame($first->nodeKinds->get(24)->id, $second->nodeKinds->get(24)->id);
    }

    public function testCommonMarkKeepsDemoDirectiveAsParagraph(): void
    {
        $compiled = new ProfileCompiler()->compile(new CommonMarkProfile());
        $buffer = new SourceBuffer("::demo alpha\n");
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $document = (new BlockParser($compiled))->parse(new ParserState($buffer, $scanner, $map, $tape));

        self::assertSame(2, $tape->kindId($tape->firstChildOrdinal($document)));
    }
}

final class DemoDirectiveProfile extends AbstractProfile
{
    public function __construct()
    {
        parent::__construct('demo-profile', [new CoreExtension(), new DemoDirectiveExtension()]);
    }
}

final class DemoDirectiveExtension extends AbstractExtension
{
    /**
     * @param list<NodeKind> $nodeKinds
     */
    public function __construct(
        private readonly ?int $directiveKind = null,
        array $nodeKinds = [],
    ) {
        parent::__construct('demo', [], ['directive'], $nodeKinds);
    }

    public function blockConstructs(): array
    {
        return null === $this->directiveKind ? [] : [new DemoDirectiveParser($this->directiveKind)];
    }

    /**
     * @param list<NodeKind> $nodeKinds
     */
    public function withNodeKinds(array $nodeKinds): ExtensionInterface
    {
        return new self($nodeKinds[0]->id, $nodeKinds);
    }
}

final class DemoDirectiveParser implements BlockConstruct
{
    public function __construct(private readonly int $kind)
    {
    }

    public function kind(): int
    {
        return $this->kind;
    }

    public function triggerBytes(): string
    {
        return ':';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        unset($containerOrdinal, $paragraphOpen);

        $first = $state->firstNonSpaceFrom();

        if ($state->columnAt($first) - $state->baseColumn() > 3) {
            return null;
        }

        $line = $state->buffer()->substring($first, $state->scanner()->contentEnd($state->line()));

        if (1 !== preg_match('/^::demo(?:[ \\t]+([A-Za-z0-9_-]+))?[ \\t]*$/', $line, $match)) {
            return null;
        }

        return new BlockStart($this->kind, $first, payload: $match[1] ?? 'demo');
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $end = $state->scanner()->contentEnd($state->line());
        $state->tape()->setEndOffset($ordinal, $end);
        $state->advanceTo($end);

        return ContinueResult::Closed;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        unset($state, $ordinal);
    }
}

final class DemoDirectiveRenderer
{
    /**
     * @param iterable<NodeKind> $nodeKinds
     */
    public function __construct(private readonly iterable $nodeKinds)
    {
    }

    public function render(string $markdown, \Alto\Markdown\Profile\CompiledProfile $profile): string
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $document = (new BlockParser($profile))->parse(new ParserState($buffer, $scanner, $map, $tape));
        $directive = $tape->firstChildOrdinal($document);
        $kind = [...$this->nodeKinds][0];

        if ($kind->id !== $tape->kindId($directive)) {
            throw new \RuntimeException('Demo directive did not parse.');
        }

        return '<directive name="'.$tape->payload($directive).'"></directive>'."\n";
    }
}
