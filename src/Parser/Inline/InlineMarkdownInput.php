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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InlineMarkdownInput
{
    public static function sourceView(string $markdown): InlineSourceView
    {
        $buffer = new SourceBuffer($markdown);
        $start = $buffer->contentStart();

        if ($start >= $buffer->length) {
            return new InlineSourceView($buffer, []);
        }

        if ($start + strcspn($markdown, "\r\n", $start) === $buffer->length) {
            return new InlineSourceView($buffer, [[$start, $buffer->length, 0]]);
        }

        $scanner = new LineScanner($buffer);
        $starts = $scanner->contentStarts();
        $ends = $scanner->contentEnds();
        $pairs = [];

        foreach ($starts as $line => $lineStart) {
            $pairs[] = [$lineStart, $ends[$line], 0];
        }

        return new InlineSourceView($buffer, $pairs);
    }
}
