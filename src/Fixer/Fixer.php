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

namespace Alto\Markdown\Fixer;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Lint\LintReport;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface Fixer
{
    public function fix(DocumentModel $model, LintReport $report, ?FixContext $context = null): FixResult;
}
