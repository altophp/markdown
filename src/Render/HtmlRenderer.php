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
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Node\Section;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface HtmlRenderer
{
    public function renderDocument(DocumentModel $model, ?RenderOptions $options = null): string;

    public function renderNode(DocumentModel $model, NodeHandle $node, ?RenderOptions $options = null): string;

    public function renderSection(DocumentModel $model, Section $section, ?RenderOptions $options = null): string;

    public function renderFragment(MarkdownFragment $fragment, ?RenderOptions $options = null): string;
}
