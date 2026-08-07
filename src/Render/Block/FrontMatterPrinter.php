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
 * Round-trips a front matter block by emitting its original source verbatim.
 *
 * The block is opaque, so nothing inside it is reformatted: the only byte the
 * printer drops is the closing fence's line ending, which the document printer
 * puts back when it joins the block to its sibling.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FrontMatterPrinter implements BlockPrinter
{
    public function print(ParsedDocumentModel $model, int $ordinal, RenderContext $context): string
    {
        return rtrim(
            $model->frontMatterText($ordinal),
            "\r\n",
        );
    }
}
