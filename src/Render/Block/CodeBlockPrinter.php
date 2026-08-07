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
final class CodeBlockPrinter implements BlockPrinter
{
    public function print(ParsedDocumentModel $model, int $ordinal, RenderContext $context): string
    {
        $code = $model->codeBlockCode($ordinal);
        $fence = $this->fence($context->style()->fenceMarker, $code);
        $language = $model->codeBlockLanguage($ordinal);
        $info = null === $language ? '' : $language;

        return $fence.$info."\n".$code.$fence;
    }

    private function fence(string $marker, string $code): string
    {
        $marker = '~' === $marker ? '~' : '`';
        preg_match_all('/'.preg_quote($marker, '/').'+/', $code, $matches);
        $max = 0;

        foreach ($matches[0] as $run) {
            $max = max($max, \strlen($run));
        }

        return str_repeat($marker, max(3, $max + 1));
    }
}
