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

namespace Alto\Markdown\Tests\Parser;

use Alto\Markdown\Extension\GitHub\GitHubAlertsExtension;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Profile\CompiledProfile;
use Alto\Markdown\Profile\GfmProfile;
use Alto\Markdown\Profile\GitHubProfile;
use Alto\Markdown\Profile\Profile;
use Alto\Markdown\Profile\ProfileCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitHubAlertParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function alertTypes(): iterable
    {
        yield 'note' => ['NOTE', 'note'];
        yield 'tip' => ['TIP', 'tip'];
        yield 'important' => ['IMPORTANT', 'important'];
        yield 'warning' => ['WARNING', 'warning'];
        yield 'caution' => ['CAUTION', 'caution'];
    }

    #[DataProvider('alertTypes')]
    public function testGitHubProfilePromotesAlertBlockquotes(string $marker, string $payload): void
    {
        $markdown = "> [!{$marker}]\n> Body\n";
        [$tape, $document, $compiled] = $this->parse($markdown, new GitHubProfile());
        $alert = $tape->firstChildOrdinal($document);
        $kind = $compiled->nodeKinds->find(GitHubAlertsExtension::ALERT_KIND);

        self::assertNotNull($kind);
        self::assertSame($kind->id, $tape->kindId($alert));
        self::assertSame($payload, $tape->payload($alert));

        $paragraph = $tape->firstChildOrdinal($alert);
        self::assertSame(BlockKind::PARAGRAPH, $tape->kindId($paragraph));
        self::assertSame('Body', $this->paragraphText($tape, $paragraph, $markdown));
    }

    public function testGitHubAlertKeepsSameLineBody(): void
    {
        $markdown = "> [!NOTE] Body\n";
        [$tape, $document, $compiled] = $this->parse($markdown, new GitHubProfile());
        $alert = $tape->firstChildOrdinal($document);
        $paragraph = $tape->firstChildOrdinal($alert);
        $kind = $compiled->nodeKinds->find(GitHubAlertsExtension::ALERT_KIND);

        self::assertNotNull($kind);
        self::assertSame($kind->id, $tape->kindId($alert));
        self::assertSame('Body', $this->paragraphText($tape, $paragraph, $markdown));
    }

    public function testGfmProfileKeepsAlertSyntaxAsBlockquote(): void
    {
        [$tape, $document] = $this->parse("> [!NOTE]\n> Body\n", new GfmProfile());
        $quote = $tape->firstChildOrdinal($document);

        self::assertSame(BlockKind::BLOCK_QUOTE, $tape->kindId($quote));
        self::assertNull($tape->payload($quote));
    }

    /**
     * @return array{ParseTape, int, CompiledProfile}
     */
    private function parse(string $markdown, Profile $profile): array
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $map, $tape);
        $compiled = new ProfileCompiler()->compile($profile);
        $document = (new BlockParser($compiled))->parse($state);

        return [$tape, $document, $compiled];
    }

    private function paragraphText(ParseTape $tape, int $paragraph, string $markdown): string
    {
        $payload = $tape->payload($paragraph) ?? '';
        $buffer = new SourceBuffer($markdown);
        $text = '';

        foreach (explode(';', $payload) as $pair) {
            [$start, $end] = array_map('intval', explode(':', $pair, 3));
            $text .= ('' === $text ? '' : "\n").$buffer->substring($start, $end);
        }

        return $text;
    }
}
