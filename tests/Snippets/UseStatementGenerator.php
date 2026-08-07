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
 * Works out which import statements a snippet needs.
 *
 * Given snippet source and a map of short class name to fully qualified name,
 * it emits a use statement for every mapped type the snippet references by a
 * bare, unqualified name that is not already imported.
 */
final class UseStatementGenerator
{
    /**
     * @param array<string, string> $classMap short name => fully qualified name
     *
     * @return list<string> use statements, sorted by fully qualified name
     */
    public function generate(string $code, array $classMap): array
    {
        $imported = $this->importedShortNames($code);

        $fqcns = [];

        foreach ($classMap as $shortName => $fqcn) {
            if (isset($imported[$shortName])) {
                continue;
            }

            if ($this->referencesBareName($code, $shortName)) {
                $fqcns[] = $fqcn;
            }
        }

        sort($fqcns);

        return array_map(static fn (string $fqcn): string => "use {$fqcn};", $fqcns);
    }

    /**
     * @return array<string, true> short name => present
     */
    private function importedShortNames(string $code): array
    {
        if (0 === preg_match_all('/\buse\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?\s*;/', $code, $matches)) {
            return [];
        }

        $imported = [];

        foreach ($matches[1] as $fqcn) {
            $segments = explode('\\', $fqcn);
            $imported[end($segments)] = true;
        }

        return $imported;
    }

    private function referencesBareName(string $code, string $shortName): bool
    {
        // A bare type reference is the short name not preceded by a word char,
        // backslash, dollar, "->" or "::" and not followed by a word char or a
        // namespace separator. That excludes imports, qualified names, method
        // and static calls, variables, and longer identifiers.
        $pattern = '/(?<![\w\\\\$>:])'.preg_quote($shortName, '/').'(?![\w\\\\])/';

        return 1 === preg_match($pattern, $code);
    }
}
