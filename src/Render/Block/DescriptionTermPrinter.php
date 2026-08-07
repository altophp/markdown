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

namespace Alto\Markdown\Render\Block;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Render\RenderContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class DescriptionTermPrinter implements BlockPrinter
{
    public function print(ParsedDocumentModel $model, int $ordinal, RenderContext $context): string
    {
        return $context->renderInlines($model, $ordinal);
    }
}
