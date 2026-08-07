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

use Alto\Markdown\Extension\Html\HtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface PlannedHtmlNodeDecorator extends HtmlNodeDecorator
{
    public function decorateWithPlan(
        HtmlNodeOutputContext $context,
        string $html,
        DocumentRenderPlan $plan,
    ): string;
}
