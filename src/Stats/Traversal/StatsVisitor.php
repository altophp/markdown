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

namespace Alto\Markdown\Stats\Traversal;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Stats\DocumentStats;
use Alto\Markdown\Stats\SectionWordCount;
use Alto\Markdown\Traversal\BlockEvent;
use Alto\Markdown\Traversal\InlineEvent;
use Alto\Markdown\Traversal\TraversalVisitor;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class StatsVisitor implements TraversalVisitor
{
    private int $headingCount = 0;

    private int $linkCount = 0;

    private int $codeBlockCount = 0;

    private int $wordCount = 0;

    private int $characterCount = 0;

    private int $maxHeadingDepth = 0;

    private int $imageCount = 0;

    private int $tableCount = 0;

    /**
     * @var array<int, int>
     */
    private array $headingsByLevel = [];

    /**
     * @var list<string>
     */
    private array $outline = [];

    /**
     * @var list<array{title: string, level: int, wordCount: int}>
     */
    private array $sectionWordCounts = [];

    /**
     * @var array<string, int>
     */
    private array $linksByType = [
        'internal' => 0,
        'external' => 0,
    ];

    /**
     * @var array<string, int>
     */
    private array $linksByHost = [];

    /**
     * @var array<string, int>
     */
    private array $codeBlocksByLanguage = [];

    private ?int $currentSection = null;

    public function enterBlock(BlockEvent $event): void
    {
        if (!$event->model instanceof ParsedDocumentModel) {
            return;
        }

        if ('atx-heading' === $event->kind->name || 'setext-heading' === $event->kind->name) {
            $this->enterHeading($event->model, $event);

            return;
        }

        if ('fenced-code' === $event->kind->name || 'indented-code' === $event->kind->name) {
            ++$this->codeBlockCount;
            $language = $event->model->codeBlockLanguage($event->id->ordinal) ?? 'plain';
            $this->codeBlocksByLanguage[$language] = ($this->codeBlocksByLanguage[$language] ?? 0) + 1;

            return;
        }

        if (GfmExtension::TABLE_KIND === $event->kind->name) {
            ++$this->tableCount;
        }
    }

    public function leaveBlock(BlockEvent $event): void {}

    public function inline(InlineEvent $event): void
    {
        if (!$event->model instanceof ParsedDocumentModel) {
            return;
        }

        if ('link' === $event->kind || 'image' === $event->kind) {
            $this->countLinkLike($event->model, $event);
        }

        if ('text' !== $event->kind && 'code-span' !== $event->kind && 'autolink' !== $event->kind) {
            return;
        }

        $text = $event->model->inlineTextValue($event->blockId->ordinal, $event->id->inlineOrdinal);
        $words = self::countWords($text);

        $this->wordCount += $words;
        $this->characterCount += self::countCharacters($text);

        if (null !== $this->currentSection) {
            $this->sectionWordCounts[$this->currentSection]['wordCount'] += $words;
        }
    }

    /**
     * @param array<string, int|float|string|bool|null> $extensionStats
     */
    public function stats(array $extensionStats = []): DocumentStats
    {
        ksort($this->headingsByLevel);
        ksort($this->linksByHost);
        ksort($this->codeBlocksByLanguage);

        return new DocumentStats(
            headingCount: $this->headingCount,
            linkCount: $this->linkCount,
            codeBlockCount: $this->codeBlockCount,
            wordCount: $this->wordCount,
            characterCount: $this->characterCount,
            readingTimeMinutes: 0 === $this->wordCount ? 0 : max(1, (int) ceil($this->wordCount / 200)),
            headingsByLevel: $this->headingsByLevel,
            maxHeadingDepth: $this->maxHeadingDepth,
            outline: $this->outline,
            sectionWordCounts: array_map(
                static fn(array $section): SectionWordCount => new SectionWordCount(
                    $section['title'],
                    $section['level'],
                    $section['wordCount'],
                ),
                $this->sectionWordCounts,
            ),
            imageCount: $this->imageCount,
            linksByType: $this->linksByType,
            linksByHost: $this->linksByHost,
            codeBlocksByLanguage: $this->codeBlocksByLanguage,
            tableCount: $this->tableCount,
            extensionStats: $extensionStats,
        );
    }

    private function enterHeading(ParsedDocumentModel $model, BlockEvent $event): void
    {
        ++$this->headingCount;
        $level = $model->headingLevel($event->id->ordinal);
        $title = $model->plainText($event->id->ordinal);

        $this->headingsByLevel[$level] = ($this->headingsByLevel[$level] ?? 0) + 1;
        $this->maxHeadingDepth = max($this->maxHeadingDepth, $level);
        $this->outline[] = str_repeat('#', $level) . ' ' . $title;
        $this->sectionWordCounts[] = [
            'title' => $title,
            'level' => $level,
            'wordCount' => 0,
        ];
        $this->currentSection = array_key_last($this->sectionWordCounts);
    }

    private function countLinkLike(ParsedDocumentModel $model, InlineEvent $event): void
    {
        [$destination] = $model->inlinePayloadParts($event->blockId->ordinal, $event->id->inlineOrdinal);

        if ('image' === $event->kind) {
            ++$this->imageCount;

            return;
        }

        ++$this->linkCount;

        if (str_starts_with($destination, '#')) {
            ++$this->linksByType['internal'];

            return;
        }

        ++$this->linksByType['external'];
        $host = parse_url($destination, \PHP_URL_HOST);

        if (\is_string($host) && '' !== $host) {
            $this->linksByHost[$host] = ($this->linksByHost[$host] ?? 0) + 1;
        }
    }

    private static function countWords(string $text): int
    {
        $result = preg_match_all('/[\p{L}\p{N}_]+/u', $text, $matches);

        return false === $result ? 0 : $result;
    }

    private static function countCharacters(string $text): int
    {
        $result = preg_match_all('/./us', $text, $matches);

        return false === $result ? \strlen($text) : \count($matches[0]);
    }
}
