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

namespace Alto\Markdown\Document;

use Alto\Markdown\Document\Handle\FrontMatterHandle;
use Alto\Markdown\Document\Handle\SectionHandle;
use Alto\Markdown\Document\Query\DocumentMarkdownQuery;
use Alto\Markdown\Document\Query\LazyCollection;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Formatter\DocumentFormatRunner;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\Linter;
use Alto\Markdown\Lint\LintFixer;
use Alto\Markdown\Lint\LintReport;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Node\CodeBlock;
use Alto\Markdown\Node\FrontMatter;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Node\Image;
use Alto\Markdown\Node\Link;
use Alto\Markdown\Node\Section;
use Alto\Markdown\Operation\Diff;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Profile\Profile;
use Alto\Markdown\Query\Collection;
use Alto\Markdown\Query\MarkdownQuery;
use Alto\Markdown\Render\HtmlDocumentRenderer;
use Alto\Markdown\Render\MarkdownRenderer;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Stats\DocumentStats;
use Alto\Markdown\Stats\DocumentStatsCollector;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
class ParsedMarkdownDocument implements MarkdownDocument
{
    public function __construct(
        private readonly Profile $profile,
        private readonly ParsedDocumentModel $model,
    ) {}

    public function profile(): Profile
    {
        return $this->profile;
    }

    public function model(): DocumentModel
    {
        return $this->model;
    }

    public function frontMatter(): ?FrontMatter
    {
        $ordinal = $this->model->frontMatterOrdinal();

        if (ParseTape::NONE === $ordinal) {
            return null;
        }

        return new FrontMatterHandle($this->model, $this->model->currentNodeId($ordinal));
    }

    public function title(): ?Heading
    {
        $heading = $this->headings(1)->first();

        return $heading instanceof Heading ? $heading : null;
    }

    public function headings(?int $level = null): Collection
    {
        /** @var LazyCollection<Heading> $collection */
        $collection = new LazyCollection(fn(): iterable => $this->model->headings($level));

        return $collection;
    }

    public function section(string $title): Section
    {
        $section = $this->sections($title)->first();

        return $section instanceof Section ? $section : new NullSection($this);
    }

    public function sections(string $title): Collection
    {
        /** @var LazyCollection<Section> $collection */
        $collection = new LazyCollection(function () use ($title): iterable {
            foreach ($this->model->sections($title) as [$id, $endOffset]) {
                yield new SectionHandle($this, $this->model, $id, $endOffset);
            }
        });

        return $collection;
    }

    public function links(): Collection
    {
        /** @var LazyCollection<Link> $collection */
        $collection = new LazyCollection(fn(): iterable => $this->model->links());

        return $collection;
    }

    public function images(): Collection
    {
        /** @var LazyCollection<Image> $collection */
        $collection = new LazyCollection(fn(): iterable => $this->model->images());

        return $collection;
    }

    public function codeBlocks(?string $language = null): Collection
    {
        /** @var LazyCollection<CodeBlock> $collection */
        $collection = new LazyCollection(fn(): iterable => $this->model->codeBlocks($language));

        return $collection;
    }

    public function query(): MarkdownQuery
    {
        return new DocumentMarkdownQuery($this->model);
    }

    public function ensure(): EnsureRunner
    {
        return new DocumentEnsureRunner($this, $this->model);
    }

    public function lint(LintConfig $config): LintReport
    {
        return (new Linter($config))->lint($this);
    }

    public function fix(LintConfig $config): MarkdownDocument
    {
        return (new LintFixer($config))->fix($this);
    }

    public function format(?MarkdownStyle $style = null): MarkdownDocument
    {
        return (new DocumentFormatRunner($this, $style ?? $this->profile->style()))->apply();
    }

    public function stats(): DocumentStats
    {
        return new DocumentStatsCollector()->collect($this->model);
    }

    public function toMarkdown(?RenderOptions $options = null): string
    {
        if ($options instanceof RenderOptions) {
            return new MarkdownRenderer()->render($this->model, $options);
        }

        if ($this->model->journal()->isEmpty()) {
            return $this->model->source()->bytes;
        }

        return new DisjointPatchLowerer()->lower($this->model, $this->model->journal())->bytes;
    }

    public function toHtml(?RenderOptions $options = null): string
    {
        return new HtmlDocumentRenderer()->renderDocument($this->model, $options);
    }

    public function hasChanges(): bool
    {
        return !$this->model->journal()->isEmpty();
    }

    public function diff(): Diff
    {
        if ($this->model->journal()->isEmpty()) {
            return new Diff(true, '');
        }

        $result = new DisjointPatchLowerer()->lower($this->model, $this->model->journal());

        return Diff::between($this->model->source()->bytes, $result->bytes);
    }
}
