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

use Alto\Markdown\Extension\CommonMark\CoreExtension;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\FrontMatter\FrontMatterExtension;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Extension\GitHub\GitHubAlertsExtension;
use Alto\Markdown\Extension\NodeKindBindableExtensionInterface;
use Alto\Markdown\Extension\NodeKindBindings;
use Alto\Markdown\Extension\SyntaxExtensionInterface;
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
use Alto\Markdown\Profile\ProfileCompiler;
use PHPUnit\Framework\TestCase;

final class NodeKindBindingTest extends TestCase
{
    public function testQualifiedKindNameBindsAtDifferentProfileLocalIds(): void
    {
        $first = $this->parse(new BindingProfile(false));
        $shifted = $this->parse(new BindingProfile(true));

        self::assertSame(24, $first[0]);
        self::assertSame(25, $shifted[0]);
        self::assertSame('example:callout', $first[1]);
        self::assertSame('example:callout', $shifted[1]);
    }

    public function testFrontMatterParserUsesItsProfileLocalKindId(): void
    {
        $compiled = new ProfileCompiler()->compile(new ShiftedGitHubProfile());
        $kind = $compiled->nodeKinds->find(FrontMatterExtension::BLOCK_KIND);

        self::assertNotNull($kind);
        self::assertSame(27, $kind->id);

        $source = new SourceBuffer("---\ntitle: Example\n---\n");
        $scanner = new LineScanner($source);
        $tape = new ParseTape();
        $state = new ParserState($source, $scanner, new LineMap($source, $scanner), $tape);
        $document = new BlockParser($compiled)->parse($state);
        $frontMatter = $tape->firstChildOrdinal($document);

        self::assertSame($kind->id, $tape->kindId($frontMatter));
        self::assertSame('4:19', $tape->payload($frontMatter));
    }

    public function testGitHubAlertPromotionUsesItsProfileLocalKindId(): void
    {
        $compiled = new ProfileCompiler()->compile(new ShiftedGitHubProfile());
        $kind = $compiled->nodeKinds->find(GitHubAlertsExtension::ALERT_KIND);

        self::assertNotNull($kind);
        self::assertSame(26, $kind->id);

        $source = new SourceBuffer("> [!NOTE]\n> Body\n");
        $scanner = new LineScanner($source);
        $tape = new ParseTape();
        $state = new ParserState($source, $scanner, new LineMap($source, $scanner), $tape);
        $document = new BlockParser($compiled)->parse($state);
        $alert = $tape->firstChildOrdinal($document);

        self::assertSame($kind->id, $tape->kindId($alert));
        self::assertSame('note', $tape->payload($alert));
    }

    /**
     * @return array{int, string}
     */
    private function parse(BindingProfile $profile): array
    {
        $compiled = new ProfileCompiler()->compile($profile);
        $source = new SourceBuffer(":::\n");
        $scanner = new LineScanner($source);
        $tape = new ParseTape();
        $state = new ParserState($source, $scanner, new LineMap($source, $scanner), $tape);
        $document = new BlockParser($compiled)->parse($state);
        $node = $tape->firstChildOrdinal($document);
        $id = $tape->kindId($node);

        return [$id, $compiled->nodeKinds->get($id)->name];
    }
}

final class BindingProfile extends AbstractProfile
{
    public function __construct(bool $shifted)
    {
        $extensions = $shifted
            ? [new ReservedKindExtension(), new BoundKindExtension()]
            : [new BoundKindExtension()];

        parent::__construct('binding', $extensions);
    }
}

final class ShiftedGitHubProfile extends AbstractProfile
{
    public function __construct()
    {
        parent::__construct(
            'shifted-github',
            [
                new CoreExtension(),
                new ReservedKindExtension(),
                new GfmExtension(),
                new GitHubAlertsExtension(),
                new FrontMatterExtension(),
            ],
        );
    }
}

final readonly class ReservedKindExtension implements ExtensionInterface, SyntaxExtensionInterface
{
    public function name(): string
    {
        return 'reserved';
    }

    public function nodeKindNames(): array
    {
        return ['placeholder'];
    }

    public function blockConstructs(): array
    {
        return [];
    }

    public function inlineConstructs(): array
    {
        return [];
    }
}

final readonly class BoundKindExtension implements NodeKindBindableExtensionInterface, SyntaxExtensionInterface
{
    public function __construct(private ?NodeKind $kind = null)
    {
    }

    public function name(): string
    {
        return 'example';
    }

    public function nodeKindNames(): array
    {
        return ['callout'];
    }

    public function bindNodeKinds(NodeKindBindings $bindings): ExtensionInterface
    {
        return new self($bindings->get('example:callout'));
    }

    public function blockConstructs(): array
    {
        return null === $this->kind ? [] : [new BoundBlockConstruct($this->kind->id)];
    }

    public function inlineConstructs(): array
    {
        return [];
    }
}

final readonly class BoundBlockConstruct implements BlockConstruct
{
    public function __construct(private int $kind)
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

        if (':::' !== $state->buffer->substring($state->firstNonSpaceFrom(), $state->lineContentEnd)) {
            return null;
        }

        return new BlockStart($this->kind, $state->lineContentEnd);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        unset($state, $ordinal);

        return ContinueResult::Closed;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        unset($state, $ordinal);
    }
}
