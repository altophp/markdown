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

use Alto\Markdown\Parser\Instrumentation;

/**
 * Shared HTML output escaping for text and attribute contexts.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HtmlEscaper
{
    private const string SPECIAL = '&<>"';

    public static function text(string $text): string
    {
        if (Instrumentation::$timing) {
            ++Instrumentation::$escapeCalls;
            Instrumentation::$escapeBytes += \strlen($text);
        }

        if (strcspn($text, self::SPECIAL) === \strlen($text)) {
            return $text;
        }

        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $text);
    }

    /**
     * Identical to text() today; kept distinct as the seam for the HTML policy package.
     */
    public static function attribute(string $text): string
    {
        if (Instrumentation::$timing) {
            ++Instrumentation::$escapeCalls;
            Instrumentation::$escapeBytes += \strlen($text);
        }

        if (strcspn($text, self::SPECIAL) === \strlen($text)) {
            return $text;
        }

        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $text);
    }
}
