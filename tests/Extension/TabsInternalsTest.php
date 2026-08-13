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

use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Document\DocumentRenderPlan;
use Alto\Markdown\Extension\Document\PlannedHtmlBlockRenderer;
use Alto\Markdown\Extension\Tabs\TabsCatalog;
use Alto\Markdown\Extension\Tabs\TabsGroupOutput;
use Alto\Markdown\Extension\Tabs\TabsItemOutput;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Source\SourceRange;
use PHPUnit\Framework\TestCase;

final class TabsInternalsTest extends TestCase
{
    public function testCatalogRejectsUnknownGroupsAndItems(): void
    {
        $catalog = new TabsCatalog();
        $actions = [
            static fn() => $catalog->registerItem(1, 2),
            static fn() => $catalog->recordTitle(2, 'Title'),
            static fn() => $catalog->item(2),
            static fn() => $catalog->groupId(1),
        ];

        foreach ($actions as $action) {
            try {
                $action();
                self::fail('An unknown tabs catalog entry must be rejected.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('Source offset', $exception->getMessage());
            }
        }
    }

    public function testCatalogRequiresRenderedItemTitles(): void
    {
        $catalog = new TabsCatalog();
        $catalog->registerGroup(1);
        $catalog->registerItem(1, 2);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has not rendered');

        $catalog->group(1);
    }

    public function testPlannedRenderersRejectTheUnplannedLane(): void
    {
        $context = $this->context();

        foreach ([new TabsGroupOutput(), new TabsItemOutput()] as $renderer) {
            try {
                $renderer->render($context, '');
                self::fail('A tabs renderer must not run without a document plan.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('requires a document render plan', $exception->getMessage());
            }
        }
    }

    public function testPlannedRenderersRequireTheirProjection(): void
    {
        $context = $this->context();
        $plan = new DocumentRenderPlan();

        foreach ([new TabsGroupOutput(), new TabsItemOutput()] as $renderer) {
            $this->assertMissingProjection($renderer, $context, $plan);
        }
    }

    private function context(): HtmlBlockOutputContext
    {
        return new HtmlBlockOutputContext(
            new BlockState(['title' => 'Tab']),
            new SourceRange(0, 0),
            '',
            HtmlPolicy::safe(),
        );
    }

    private function assertMissingProjection(
        PlannedHtmlBlockRenderer $renderer,
        HtmlBlockOutputContext $context,
        DocumentRenderPlan $plan,
    ): void {
        try {
            $renderer->renderWithPlan($context, '', $plan);
            self::fail('A tabs renderer must require its catalog projection.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Missing tabs projection', $exception->getMessage());
        }
    }
}
