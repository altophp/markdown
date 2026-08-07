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

namespace Alto\Markdown\Stats;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DocumentStats
{
    /**
     * @param array<int, int>                           $headingsByLevel
     * @param list<string>                              $outline
     * @param list<SectionWordCount>                    $sectionWordCounts
     * @param array<string, int>                        $linksByType
     * @param array<string, int>                        $linksByHost
     * @param array<string, int>                        $codeBlocksByLanguage
     * @param array<string, int|float|string|bool|null> $extensionStats
     */
    public function __construct(
        public int $headingCount,
        public int $linkCount,
        public int $codeBlockCount,
        public int $wordCount,
        public int $characterCount,
        public int $readingTimeMinutes,
        public array $headingsByLevel,
        public int $maxHeadingDepth,
        public array $outline,
        public array $sectionWordCounts,
        public int $imageCount,
        public array $linksByType,
        public array $linksByHost,
        public array $codeBlocksByLanguage,
        public int $tableCount,
        public array $extensionStats = [],
    ) {
    }
}
