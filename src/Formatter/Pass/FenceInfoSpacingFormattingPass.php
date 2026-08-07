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
use Alto\Markdown\Formatter\BlockEventCollector;
use Alto\Markdown\Formatter\FormatResult;
use Alto\Markdown\Formatter\FormattingLevel;
use Alto\Markdown\Formatter\FormattingPass;
use Alto\Markdown\Operation\FenceInfoSpacingOperationFactory;
use Alto\Markdown\Render\MarkdownStyle;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FenceInfoSpacingFormattingPass implements FormattingPass
{
    public function level(): FormattingLevel
    {
        return FormattingLevel::Block;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        $factory = new FenceInfoSpacingOperationFactory();
        $operations = [];

        foreach (BlockEventCollector::collect($model) as $event) {
            $operation = $factory->create($model, $event, 'format code fence info spacing');

            if (null !== $operation) {
                $operations[] = $operation;
            }
        }

        return new FormatResult($operations);
    }
}
