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

use Alto\Markdown\Node\Kind\DefaultNodeKindRegistry;
use PHPUnit\Framework\TestCase;

final class NodeKindRegistryTest extends TestCase
{
    public function testCoreKindsHaveStableIds(): void
    {
        $registry = new DefaultNodeKindRegistry();

        self::assertSame(1, $registry->core('document')->id);
        self::assertSame(2, $registry->core('paragraph')->id);
        self::assertSame(23, $registry->core('html-inline')->id);
        self::assertSame('paragraph', $registry->get(2)->name);
    }

    public function testReserveAllocatesDeterministicExtensionKinds(): void
    {
        $registry = new DefaultNodeKindRegistry();

        $table = $registry->reserve('gfm', 'table');
        $task = $registry->reserve('gfm', 'task-list-item');

        self::assertSame(24, $table->id);
        self::assertSame('gfm:table', $table->name);
        self::assertSame(25, $task->id);
        self::assertSame($table, $registry->reserve('gfm', 'table'));
    }
}
