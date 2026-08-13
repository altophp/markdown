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

namespace Alto\Markdown\Extension\TableOfContents;

use Alto\Markdown\Extension\Html\HtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class TableOfContentsHeadingDecorator implements HtmlNodeDecorator
{
    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        $level = $context->int('level');
        $open = '<h' . $level;
        $openAt = stripos($html, $open);

        if (false === $openAt) {
            return $html;
        }

        $openEnd = strpos($html, '>', $openAt + \strlen($open));
        if (false === $openEnd) {
            return $html;
        }

        $id = TableOfContentsCatalog::targetId($context->string('slug'));
        $escaped = $context->escapeAttribute($id);
        $opening = substr($html, $openAt, $openEnd - $openAt + 1);

        if (1 === preg_match('/\sid=(["\'])(.*?)\1/i', $opening, $match)) {
            return $escaped === $match[2]
                ? $html
                : '<span id="' . $escaped . '"></span>' . $html;
        }

        $insert = $openAt + \strlen($open);

        return substr($html, 0, $insert) . ' id="' . $escaped . '"' . substr($html, $insert);
    }
}
