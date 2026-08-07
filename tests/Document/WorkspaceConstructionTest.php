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

namespace Alto\Markdown\Tests\Document;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use PHPUnit\Framework\TestCase;

final class WorkspaceConstructionTest extends TestCase
{
    public function testRebaseReplacesSyntaxWithoutConstructingDetachedWorkspace(): void
    {
        Instrumentation::reset();
        $document = Markdown::commonmark()->fromString("Before\n");
        $model = $document->model();

        self::assertInstanceOf(ParsedDocumentModel::class, $model);
        self::assertSame(1, Instrumentation::$syntaxParses);
        self::assertSame(1, Instrumentation::$documentWorkspaces);
        self::assertSame(1, Instrumentation::$workspaceTapeClones);
        self::assertSame(1, Instrumentation::$referenceMapClones);

        $model->rebase("After\n");

        self::assertSame("After\n", $model->source()->bytes);
        self::assertSame(2, Instrumentation::$syntaxParses);
        self::assertSame(1, Instrumentation::$documentWorkspaces);
        self::assertSame(2, Instrumentation::$workspaceTapeClones);
        self::assertSame(2, Instrumentation::$referenceMapClones);
    }

    public function testWorkspaceConstructionDefersEditMachinery(): void
    {
        Instrumentation::reset();
        $document = Markdown::commonmark()->fromString("# Title\n\nBody **text**.\n");

        self::assertSame(1, Instrumentation::$documentWorkspaces);
        self::assertSame(0, Instrumentation::$inlineCaches);
        self::assertSame(0, Instrumentation::$editJournals);

        $document->toHtml();

        self::assertSame(1, Instrumentation::$inlineCaches);
        self::assertSame(0, Instrumentation::$editJournals);

        $model = $document->model();

        self::assertInstanceOf(ParsedDocumentModel::class, $model);
        self::assertTrue($model->journal()->isEmpty());
        self::assertSame(1, Instrumentation::$editJournals);
        self::assertSame($model->journal(), $model->journal());
    }
}
