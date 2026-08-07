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

namespace Alto\Markdown\Extension\Document;

use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface PlannedHtmlBlockRenderer extends HtmlBlockRenderer
{
    public function renderWithPlan(
        HtmlBlockOutputContext $context,
        string $children,
        DocumentRenderPlan $plan,
    ): string;
}
