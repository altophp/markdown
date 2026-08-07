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

namespace Alto\Markdown\Extension\Footnote;

use Alto\Markdown\Extension\Document\DocumentProjectionTransform;
use Alto\Markdown\Extension\Document\DocumentRenderProjection;
use Alto\Markdown\Extension\Document\DocumentTransformContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FootnoteTransform implements DocumentProjectionTransform
{
    private ?FootnoteCatalog $catalog = null;

    public function transform(DocumentTransformContext $context): void
    {
        unset($context);

        $this->catalog = new FootnoteCatalog();
    }

    public function projection(): DocumentRenderProjection
    {
        return $this->catalog
            ?? throw new \LogicException('The footnote transform has not run.');
    }
}
