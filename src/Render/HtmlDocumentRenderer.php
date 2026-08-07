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

namespace Alto\Markdown\Render;

use Alto\Markdown\Builder\MarkdownFragment;
use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Document\ParsedMarkdownFactory;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\RenderException;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Node\Section;
use Alto\Markdown\Profile\GitHubProfile;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HtmlDocumentRenderer implements HtmlRenderer
{
    private HtmlRendererEngine $engine;

    public function __construct()
    {
        $this->engine = new HtmlRendererEngine();
    }

    public function renderDocument(DocumentModel $model, ?RenderOptions $options = null): string
    {
        return $this->engine->renderDocument($this->assertModel($model), $this->policy($options));
    }

    public function renderNode(DocumentModel $model, NodeHandle $node, ?RenderOptions $options = null): string
    {
        return $this->engine->renderNode($this->assertModel($model), $node->id()->ordinal, $this->policy($options));
    }

    public function renderSection(DocumentModel $model, Section $section, ?RenderOptions $options = null): string
    {
        return $this->engine->renderSection(
            $this->assertModel($model),
            $section->id()->ordinal,
            $section->range()->endOffset,
            $this->policy($options),
        );
    }

    private function policy(?RenderOptions $options): HtmlPolicy
    {
        return $options->htmlPolicy ?? HtmlPolicy::safe();
    }

    public function renderFragment(MarkdownFragment $fragment, ?RenderOptions $options = null): string
    {
        $profile = null === $options ? new GitHubProfile() : ($options->targetProfile ?? new GitHubProfile());

        return new ParsedMarkdownFactory($profile)->toHtml($fragment->toMarkdown($options), renderOptions: $options);
    }

    private function assertModel(DocumentModel $model): ParsedDocumentModel
    {
        if (!$model instanceof ParsedDocumentModel) {
            throw new RenderException('HtmlDocumentRenderer requires a parsed document model.');
        }

        return $model;
    }
}
