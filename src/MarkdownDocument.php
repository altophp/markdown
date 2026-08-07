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

namespace Alto\Markdown;

use Alto\Markdown\Document\EnsureRunner;
use Alto\Markdown\Lint\LintConfig;
use Alto\Markdown\Lint\LintReport;
use Alto\Markdown\Node\CodeBlock;
use Alto\Markdown\Node\FrontMatter;
use Alto\Markdown\Node\Heading;
use Alto\Markdown\Node\Image;
use Alto\Markdown\Node\Link;
use Alto\Markdown\Node\Section;
use Alto\Markdown\Operation\Diff;
use Alto\Markdown\Profile\Profile;
use Alto\Markdown\Query\Collection;
use Alto\Markdown\Query\MarkdownQuery;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Render\RenderOptions;
use Alto\Markdown\Stats\DocumentStats;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface MarkdownDocument
{
    public function profile(): Profile;

    public function model(): DocumentModel;

    /**
     * The document's opaque front matter block, or null when it has none.
     * Only profiles that enable the front matter feature ever return one.
     */
    public function frontMatter(): ?FrontMatter;

    public function title(): ?Heading;

    /**
     * @return Collection<Heading>
     */
    public function headings(?int $level = null): Collection;

    public function section(string $title): Section;

    /**
     * @return Collection<Section>
     */
    public function sections(string $title): Collection;

    /**
     * @return Collection<Link>
     */
    public function links(): Collection;

    /**
     * @return Collection<Image>
     */
    public function images(): Collection;

    /**
     * @return Collection<CodeBlock>
     */
    public function codeBlocks(?string $language = null): Collection;

    public function query(): MarkdownQuery;

    public function ensure(): EnsureRunner;

    /**
     * Analyse the document without changing it.
     */
    public function lint(LintConfig $config): LintReport;

    /**
     * Apply every safe fix selected by the configuration.
     */
    public function fix(LintConfig $config): self;

    /**
     * Apply the configured formatting passes immediately.
     */
    public function format(?MarkdownStyle $style = null): self;

    public function stats(): DocumentStats;

    /**
     * Return source-preserving edited bytes, or serialize the model when
     * render options are passed explicitly.
     */
    public function toMarkdown(?RenderOptions $options = null): string;

    public function toHtml(?RenderOptions $options = null): string;

    public function hasChanges(): bool;

    /**
     * Preview pending source changes without writing.
     */
    public function diff(): Diff;
}
