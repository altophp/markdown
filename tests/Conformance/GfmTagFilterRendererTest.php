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

namespace Alto\Markdown\Tests\Conformance;

use Alto\Markdown\Profile\GfmProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use PHPUnit\Framework\TestCase;

final class GfmTagFilterRendererTest extends TestCase
{
    public function testGfmFiltersDisallowedInlineAndBlockRawHtmlTags(): void
    {
        $renderer = new TestHtmlRenderer((new ProfileCompiler())->compile(new GfmProfile()));

        self::assertSame(
            "<p><strong> &lt;title> &lt;SCRIPT></p>\n<blockquote>\n&lt;xmp>\n</blockquote>\n",
            $renderer->render("<strong> <title> <SCRIPT>\n\n<blockquote>\n<xmp>\n</blockquote>\n"),
        );
    }

    public function testCommonMarkLeavesDisallowedRawHtmlTagsUntouched(): void
    {
        self::assertSame(
            "<p><strong> <title></p>\n<blockquote>\n<xmp>\n</blockquote>\n",
            new TestHtmlRenderer()->render("<strong> <title>\n\n<blockquote>\n<xmp>\n</blockquote>\n"),
        );
    }
}
