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

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface MultiPatchOperation extends Operation
{
    /**
     * @return list<SourcePatch>
     */
    public function toPatches(DocumentModel $model): array;
}
