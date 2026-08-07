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

namespace Alto\Markdown\Formatter\Pass;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Formatter\FormatResult;
use Alto\Markdown\Formatter\FormattingLevel;
use Alto\Markdown\Formatter\FormattingPass;
use Alto\Markdown\Operation\FinalNewlineOperationFactory;
use Alto\Markdown\Render\MarkdownStyle;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FinalNewlineFormattingPass implements FormattingPass
{
    public function level(): FormattingLevel
    {
        return FormattingLevel::Source;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        if (!$style->finalNewline) {
            return new FormatResult();
        }

        $operation = new FinalNewlineOperationFactory()->create($model, 'format final newline');

        return new FormatResult(null === $operation ? [] : [$operation]);
    }
}
