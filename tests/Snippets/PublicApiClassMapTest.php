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

namespace Alto\Markdown\Tests\Snippets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PublicApiClassMapTest extends TestCase
{
    public function testMapsRootLevelType(): void
    {
        $map = $this->buildFromFixture([
            'MarkdownDocument.php' => "<?php\n",
        ]);

        self::assertSame(['MarkdownDocument' => 'Alto\Markdown\MarkdownDocument'], $map);
    }

    public function testMapsNestedTypeThroughPsr4(): void
    {
        $map = $this->buildFromFixture([
            'Stats/DocumentStats.php' => "<?php\n",
        ]);

        self::assertSame(['DocumentStats' => 'Alto\Markdown\Stats\DocumentStats'], $map);
    }

    public function testDropsCollidingShortNames(): void
    {
        $map = $this->buildFromFixture([
            'Node/NodeId.php' => "<?php\n",
            'Query/NodeId.php' => "<?php\n",
            'MarkdownDocument.php' => "<?php\n",
        ]);

        self::assertSame(['MarkdownDocument' => 'Alto\Markdown\MarkdownDocument'], $map);
    }

    public function testIgnoresNonPhpFiles(): void
    {
        $map = $this->buildFromFixture([
            'MarkdownDocument.php' => "<?php\n",
            'notes.txt' => 'ignore me',
        ]);

        self::assertSame(['MarkdownDocument' => 'Alto\Markdown\MarkdownDocument'], $map);
    }

    public function testResolvesAgainstRealSourceTree(): void
    {
        $map = PublicApiClassMap::build(\dirname(__DIR__, 2).'/src', 'Alto\Markdown');

        self::assertSame('Alto\Markdown\MarkdownDocument', $map['MarkdownDocument'] ?? null);
        self::assertSame('Alto\Markdown\Stats\DocumentStats', $map['DocumentStats'] ?? null);
    }

    /**
     * @param array<string, string> $files relative path => contents
     *
     * @return array<string, string>
     */
    private function buildFromFixture(array $files): array
    {
        $root = sys_get_temp_dir().'/alto-classmap-'.bin2hex(random_bytes(6));

        foreach ($files as $relative => $contents) {
            $path = $root.'/'.$relative;
            $dir = \dirname($path);

            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                self::fail("Cannot create {$dir}");
            }

            file_put_contents($path, $contents);
        }

        try {
            return PublicApiClassMap::build($root, 'Alto\Markdown');
        } finally {
            $this->removeTree($root);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path)) {
            $entries = scandir($path);

            foreach (false === $entries ? [] : $entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    $this->removeTree($path.'/'.$entry);
                }
            }

            rmdir($path);

            return;
        }

        if (is_file($path)) {
            unlink($path);
        }
    }
}
