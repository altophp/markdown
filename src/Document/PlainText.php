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

namespace Alto\Markdown\Document;

use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParseTape;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class PlainText
{
    public static function fromInlineTape(SourceBuffer $buffer, ParseTape $inlineTape, int $parent): string
    {
        $text = '';
        $node = $inlineTape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $node) {
            $text .= match ($inlineTape->kindId($node)) {
                InlineKind::TEXT, InlineKind::CODE_SPAN => $inlineTape->payload($node) ?? $buffer->substring($inlineTape->startOffset($node), $inlineTape->endOffset($node)),
                InlineKind::SOFT_BREAK, InlineKind::HARD_BREAK => "\n",
                InlineKind::AUTOLINK, InlineKind::HTML_INLINE => $buffer->substring($inlineTape->startOffset($node), $inlineTape->endOffset($node)),
                default => $inlineTape->hasExtensionInlineNode($node)
                    ? $inlineTape->extensionInlineNode($node)->text
                    : self::fromInlineTape($buffer, $inlineTape, $node),
            };
            $node = $inlineTape->nextSiblingOrdinal($node);
        }

        return $text;
    }

    private function __construct()
    {
    }
}
