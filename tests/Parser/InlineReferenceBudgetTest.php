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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Exception\InlineCountLimitException;
use Alto\Markdown\Exception\ParseLimitException;
use Alto\Markdown\Exception\ReferenceCountLimitException;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseOptions;
use PHPUnit\Framework\TestCase;

final class InlineReferenceBudgetTest extends TestCase
{
    public function testInlineNodesAtTheConfiguredLimitParse(): void
    {
        $options = (new ParseOptions())->withMaxInlineCount(3);

        self::assertSame("<p><em>a</em></p>\n", Markdown::commonmark()->toHtml("*a*\n", $options));
    }

    public function testInlineNodePastTheConfiguredLimitIsRefused(): void
    {
        $options = (new ParseOptions())->withMaxInlineCount(2);

        try {
            Markdown::commonmark()->toHtml("*a*\n", $options);
            self::fail('Expected the inline-node budget to be enforced.');
        } catch (InlineCountLimitException $exception) {
            self::assertInstanceOf(ParseLimitException::class, $exception);
            self::assertSame(2, $exception->maxInlineCount);
            self::assertSame(3, $exception->attemptedInlineCount);
            self::assertSame(2, $exception->byteOffset);
        }
    }

    public function testDocumentConstructionRemainsLazyUntilInlineContentIsRead(): void
    {
        Instrumentation::reset();
        $options = (new ParseOptions())->withMaxInlineCount(1);
        $document = Markdown::commonmark()->fromString("A\n\nB\n", $options);

        self::assertSame(0, Instrumentation::$inlineParses);

        $this->expectException(InlineCountLimitException::class);

        $document->toHtml();
    }

    public function testTypedQueriesEnforceTheSameInlineBudget(): void
    {
        $options = (new ParseOptions())->withMaxInlineCount(2);
        $document = Markdown::commonmark()->fromString("[label](/url)\n", $options);

        $this->expectException(InlineCountLimitException::class);

        $document->links()->count();
    }

    public function testInlineBudgetIsSharedAcrossRichTableCells(): void
    {
        $options = (new ParseOptions())->withMaxInlineCount(1);

        try {
            Markdown::gfm()->toHtml("| A | B |\n| - | - |\n", $options);
            self::fail('Expected the shared inline budget to be exceeded.');
        } catch (InlineCountLimitException $exception) {
            self::assertNull($exception->byteOffset);
        }
    }

    public function testConfiguredInlineBudgetCannotUseTheUncountedFusedLane(): void
    {
        Instrumentation::reset();
        $options = (new ParseOptions())->withMaxInlineCount(1);

        Markdown::commonmark()->toHtml("A\n", $options);

        self::assertSame(1, Instrumentation::$inlineParses);
        self::assertSame(0, Instrumentation::$fusedInlineRenders);
    }

    public function testRebaseResetsAndPreservesTheInlineBudget(): void
    {
        $options = (new ParseOptions())->withMaxInlineCount(1);
        $document = Markdown::commonmark()->fromString("A\n", $options);

        self::assertSame("<p>A</p>\n", $document->toHtml());

        $model = $document->model();
        self::assertInstanceOf(ParsedDocumentModel::class, $model);
        $model->rebase("B\n");

        self::assertSame("<p>B</p>\n", $document->toHtml());

        $model->rebase("[label](/url)\n");

        $this->expectException(InlineCountLimitException::class);

        $document->toHtml();
    }

    public function testUniqueReferencesAtTheConfiguredLimitParse(): void
    {
        $options = (new ParseOptions())->withMaxReferenceCount(2);

        self::assertSame('', Markdown::commonmark()->toHtml("[a]: /a\n[b]: /b\n", $options));
    }

    public function testDuplicateReferencesDoNotConsumeTheBudget(): void
    {
        $options = (new ParseOptions())->withMaxReferenceCount(1);

        self::assertSame('', Markdown::commonmark()->toHtml("[a]: /a\n[a]: /ignored\n", $options));
    }

    public function testUniqueReferencePastTheConfiguredLimitIsRefused(): void
    {
        $options = (new ParseOptions())->withMaxReferenceCount(1);

        try {
            Markdown::commonmark()->toHtml("[a]: /a\n[b]: /b\n", $options);
            self::fail('Expected the reference-count budget to be enforced.');
        } catch (ReferenceCountLimitException $exception) {
            self::assertInstanceOf(ParseLimitException::class, $exception);
            self::assertSame(1, $exception->maxReferenceCount);
            self::assertSame(2, $exception->attemptedReferenceCount);
            self::assertSame(8, $exception->byteOffset);
        }
    }

    public function testDocumentLaneEnforcesTheReferenceBudget(): void
    {
        $options = (new ParseOptions())->withMaxReferenceCount(1);

        $this->expectException(ReferenceCountLimitException::class);

        Markdown::commonmark()->fromString("[a]: /a\n[b]: /b\n", $options);
    }

    public function testRebasePreservesTheReferenceBudgetAndOriginalModelOnFailure(): void
    {
        $options = (new ParseOptions())->withMaxReferenceCount(1);
        $document = Markdown::commonmark()->fromString("Original\n", $options);
        $model = $document->model();
        self::assertInstanceOf(ParsedDocumentModel::class, $model);

        try {
            $model->rebase("[a]: /a\n[b]: /b\n");
            self::fail('Expected the reference budget to reject the rebase.');
        } catch (ReferenceCountLimitException) {
            self::assertSame("Original\n", $document->toMarkdown());
        }
    }
}
