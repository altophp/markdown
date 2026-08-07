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

/**
 * Builds a short-name to fully-qualified-name map from a PSR-4 source tree.
 *
 * The file path relative to the source root becomes the class name via the
 * PSR-4 prefix. Short names that resolve to more than one class are dropped so
 * an ambiguous bare reference never gets an incorrect import.
 */
final class PublicApiClassMap
{
    /**
     * @return array<string, string> short name => fully qualified name
     */
    public static function build(string $sourceDir, string $namespacePrefix): array
    {
        $root = rtrim($sourceDir, '/');
        $prefix = trim($namespacePrefix, '\\');

        if (!is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var array<string, string> $byShortName */
        $byShortName = [];
        /** @var array<string, true> $ambiguous */
        $ambiguous = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $classPath = str_replace('/', '\\', substr($relative, 0, -\strlen('.php')));
            $fqcn = $prefix.'\\'.$classPath;
            $shortName = $file->getBasename('.php');

            if (isset($byShortName[$shortName])) {
                $ambiguous[$shortName] = true;

                continue;
            }

            $byShortName[$shortName] = $fqcn;
        }

        foreach (array_keys($ambiguous) as $shortName) {
            unset($byShortName[$shortName]);
        }

        ksort($byShortName);

        return $byShortName;
    }
}
