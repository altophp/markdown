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

namespace Alto\Markdown\Render;

/**
 * Replaces one URL attribute in trusted renderer output.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HtmlUrlAttributeRewriter
{
    public static function first(string $html, string $tag, string $attribute, string $escapedValue): string
    {
        $pattern = '/(<'.preg_quote($tag, '/').'(?=[\\s>])'
            .'(?:(?:"[^"]*"|\'[^\']*\'|[^\'">])*)'
            .'\\s'.preg_quote($attribute, '/').'\\s*=\\s*)'
            .'(?:"[^"]*"|\'[^\']*\'|[^\\s>]+)/i';

        return preg_replace_callback(
            $pattern,
            static fn (array $match): string => $match[1].'"'.$escapedValue.'"',
            $html,
            1,
        ) ?? $html;
    }
}
