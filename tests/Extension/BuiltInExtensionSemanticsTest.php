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

use Alto\Markdown\Markdown;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;
use Alto\Markdown\Traversal\TapeDocumentTraversal;
use Alto\Markdown\Traversal\TraversalOptions;
use Alto\Markdown\Traversal\TraversalVisitor;
use PHPUnit\Framework\TestCase;

final class BuiltInExtensionSemanticsTest extends TestCase
{
    public function testGitHubBuiltInsExposeStableSemanticNames(): void
    {
        $source = <<<'MARKDOWN'
            ---
            title: Example
            ---

            | Name |
            | --- |
            | Alto |

            - [x] ~~done~~

            > [!NOTE]
            > Alert
            MARKDOWN;
        $visitor = new BuiltInKindRecordingVisitor();
        $document = Markdown::github()->fromString($source);

        new TapeDocumentTraversal()->traverse(
            $document->model(),
            $visitor,
            new TraversalOptions(includeInlines: true),
        );

        self::assertSame(
            [
                'document',
                'frontmatter:block',
                'gfm:table',
                'list',
                'list-item',
                'paragraph',
                'github:alert',
                'paragraph',
            ],
            $visitor->blocks,
        );
        self::assertContains('strikethrough', $visitor->inlines);
        self::assertSame(
            "<table>\n<thead>\n<tr>\n<th>Name</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>Alto</td>\n</tr>\n</tbody>\n</table>\n"
            ."<ul>\n<li><input checked=\"\" disabled=\"\" type=\"checkbox\"> <del>done</del></li>\n</ul>\n"
            ."<div class=\"markdown-alert markdown-alert-note\">\n<p class=\"markdown-alert-title\">Note</p>\n<p>Alert</p>\n</div>\n",
            $document->toHtml(),
        );
    }
}

final class BuiltInKindRecordingVisitor implements TraversalVisitor
{
    /**
     * @var list<string>
     */
    public array $blocks = [];

    /**
     * @var list<string>
     */
    public array $inlines = [];

    public function enterBlock(BlockEvent $event): void
    {
        $this->blocks[] = $event->kind->name;
    }

    public function leaveBlock(BlockEvent $event): void
    {
    }

    public function inline(InlineEvent $event): void
    {
        $this->inlines[] = $event->kind;
    }
}
