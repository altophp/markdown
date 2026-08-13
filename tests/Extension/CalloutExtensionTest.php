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

use Alto\Markdown\Document\ParsedMarkdownFactory;
use Alto\Markdown\Exception\RenderException;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Profile\Profile;
use Alto\Markdown\Profile\ProfileCompiler;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Tests\Extension\Callout\CalloutKind;
use Alto\Markdown\Tests\Extension\Callout\CalloutProfile;
use Alto\Markdown\Tests\Extension\Callout\PlainCommonMarkProfile;
use Alto\Markdown\Tests\Extension\Callout\ShiftedProfile;
use PHPUnit\Framework\TestCase;

/**
 * Executable evidence for the custom block contract documented in
 * docs/extensions.md. Tests that assert a throw or a wrong id pin behavior so
 * the public guide cannot drift from the code.
 */
final class CalloutExtensionTest extends TestCase
{
    public function testCalloutParsesAsAContainerBlock(): void
    {
        $markdown = ":::note\nBody text.\n:::\n";
        [$tape, $document] = $this->parse($markdown, new CalloutProfile());

        $callout = $tape->firstChildOrdinal($document);

        self::assertSame(CalloutKind::CALLOUT, $tape->kindId($callout));
        self::assertSame('note', $tape->payload($callout));
        self::assertSame(3, $tape->flags($callout));

        $paragraph = $tape->firstChildOrdinal($callout);

        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame('Body text.', $this->paragraphText($tape, $paragraph, $markdown));
        self::assertSame(ParseTape::NONE, $tape->nextSiblingOrdinal($callout));
    }

    public function testCalloutBodyGoesThroughTheOrdinaryBlockLoop(): void
    {
        $markdown = ":::warning\n# Heading\n\n> quote\n:::\n";
        [$tape, $document] = $this->parse($markdown, new CalloutProfile());

        $callout = $tape->firstChildOrdinal($document);
        $heading = $tape->firstChildOrdinal($callout);
        $quote = $tape->nextSiblingOrdinal($heading);

        self::assertSame('warning', $tape->payload($callout));
        self::assertSame(BlockKind::ATX_HEADING, $tape->kindId($heading));
        self::assertSame(BlockKind::BLOCK_QUOTE, $tape->kindId($quote));
    }

    public function testUnlabelledOpeningFenceIsNotACallout(): void
    {
        [$tape, $document] = $this->parse(":::\nBody\n", new CalloutProfile());

        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($tape->firstChildOrdinal($document)));
    }

    public function testCommonMarkProfileLeavesCalloutSyntaxAlone(): void
    {
        [$tape, $document] = $this->parse(":::note\nBody\n:::\n", new PlainCommonMarkProfile());

        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($tape->firstChildOrdinal($document)));
    }

    /**
     * The construct hard-codes its node kind id because ProfileCompiler never
     * hands the reserved NodeKind back to the extension that declared it
     * (src/Profile/ProfileCompiler.php:115). This is the guard every extension
     * author has to write by hand.
     */
    public function testReservedIdMatchesTheHardCodedConstant(): void
    {
        $compiled = new ProfileCompiler()->compile(new CalloutProfile());

        self::assertSame(CalloutKind::CALLOUT, $compiled->nodeKinds->get(CalloutKind::CALLOUT)->id);
        self::assertSame('example:callout', $compiled->nodeKinds->get(CalloutKind::CALLOUT)->name);
    }

    public function testGfmTableKeepsItsQualifiedKindWhenAnExtensionIsInsertedFirst(): void
    {
        $compiled = new ProfileCompiler()->compile(new ShiftedProfile());
        $tableKind = $compiled->nodeKinds->find(GfmExtension::TABLE_KIND);

        self::assertNotNull($tableKind);
        self::assertSame('example:callout', $compiled->nodeKinds->get(CalloutKind::CALLOUT)->name);
        self::assertSame(25, $tableKind->id);

        $markdown = "| A |\n| - |\n| 1 |\n";
        [$tape, $document] = $this->parse($markdown, new ShiftedProfile());
        $table = $tape->firstChildOrdinal($document);

        self::assertSame($tableKind->id, $tape->kindId($table));
        self::assertSame(GfmExtension::TABLE_KIND, $compiled->nodeKinds->get($tape->kindId($table))->name);
    }

    public function testShiftedGfmTableKeepsSpecializedOutputsQueriesAndStats(): void
    {
        $markdown = "| A |\n| - |\n| 1 |\n";
        $factory = new ParsedMarkdownFactory(new ShiftedProfile());
        $document = $factory->fromString($markdown);

        self::assertSame($factory->toHtml($markdown), $document->toHtml());
        self::assertSame("| A |\n| --- |\n| 1 |\n", $document->toMarkdown(new RenderOptions()));
        self::assertCount(1, $document->query()->kind(GfmExtension::TABLE_KIND)->get()->all());
        self::assertSame(1, $document->stats()->tableCount);
    }

    /**
     * Reaching a document at all means instantiating ParsedMarkdownFactory,
     * which is @internal (src/Document/ParsedMarkdownFactory.php:29); Markdown
     * only exposes three fixed profiles (src/Markdown.php:14-27).
     */
    public function testQueryFindsTheExtensionKindByQualifiedName(): void
    {
        $document = new ParsedMarkdownFactory(new CalloutProfile())
            ->fromString(":::note\nBody text.\n:::\n");

        $handles = $document->query()
            ->kind('example:callout')
            ->get()
            ->all();

        self::assertCount(1, $handles);
        self::assertSame('example:callout', $handles[0]->kind()->name);
    }

    /**
     * Unknown qualified names produce an empty query result.
     */
    public function testQueryRejectsAnUnknownQualifiedName(): void
    {
        $document = new ParsedMarkdownFactory(new CalloutProfile())
            ->fromString(":::note\nBody text.\n:::\n");

        self::assertCount(0, $document->query()->kind('example:missing')->get()->all());
    }

    /**
     * HTML emission is a closed match on the kind name inside the @internal,
     * final HtmlRendererEngine (src/Render/HtmlRendererEngine.php:125-139).
     * An extension can add parsing; it cannot add rendering.
     */
    public function testHtmlRenderingHasNoSeamForANewKind(): void
    {
        $factory = new ParsedMarkdownFactory(new CalloutProfile());

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('No HTML renderer for node kind "example:callout".');

        $factory->toHtml(":::note\nBody text.\n:::\n");
    }

    /**
     * Markdown printing is keyed by kind name too, from a private readonly map
     * built in the constructor (src/Render/MarkdownRenderer.php:52-65), so
     * round-tripping a document that contains the new kind fails the same way.
     */
    public function testMarkdownPrintingHasNoSeamForANewKind(): void
    {
        $document = new ParsedMarkdownFactory(new CalloutProfile())
            ->fromString(":::note\nBody text.\n:::\n");

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('No Markdown printer registered for node kind "example:callout".');

        $document->toMarkdown(new RenderOptions());
    }

    /**
     * @return array{ParseTape, int}
     */
    private function parse(string $markdown, Profile $profile): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $map, $tape);
        $compiled = new ProfileCompiler()->compile($profile);
        $document = new BlockParser($compiled)->parse($state);

        return [$tape, $document];
    }

    private function paragraphText(ParseTape $tape, int $paragraph, string $markdown): string
    {
        $payload = $tape->payload($paragraph) ?? '';
        $buffer = new SourceBuffer($markdown);
        $text = '';

        foreach (explode(';', $payload) as $pair) {
            [$start, $end] = array_map('intval', explode(':', $pair, 3));
            $text .= ('' === $text ? '' : "\n") . $buffer->substring($start, $end);
        }

        return $text;
    }
}
