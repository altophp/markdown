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

namespace Alto\Markdown\Operation;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FinalNewlineOperationFactory
{
    public function create(DocumentModel $model, string $description): ?SourcePatchOperation
    {
        $bytes = $model->source()->bytes;

        if ('' === $bytes || str_ends_with($bytes, "\n") || str_ends_with($bytes, "\r")) {
            return null;
        }

        $offset = \strlen($bytes);
        $range = new SourceRange($offset, $offset);

        return new SourcePatchOperation(new SourcePatch(
            $range,
            $model->source()->dominantEol->value,
            $range,
            $description,
        ));
    }
}
