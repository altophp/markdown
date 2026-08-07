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

final class GfmTaskListRendererTest extends TestCase
{
    public function testGfmRendersTaskMarkersAsDisabledCheckboxes(): void
    {
        $renderer = new TestHtmlRenderer((new ProfileCompiler())->compile(new GfmProfile()));

        self::assertSame(
            "<ul>\n<li><input disabled=\"\" type=\"checkbox\"> todo</li>\n<li><input checked=\"\" disabled=\"\" type=\"checkbox\"> done</li>\n</ul>\n",
            $renderer->render("- [ ] todo\n- [X] done\n"),
        );
    }

    public function testGfmKeepsLooseTaskMarkersInsideTheFirstParagraph(): void
    {
        $renderer = new TestHtmlRenderer((new ProfileCompiler())->compile(new GfmProfile()));

        self::assertSame(
            "<ul>\n<li>\n<p><input disabled=\"\" type=\"checkbox\"> todo</p>\n</li>\n<li>\n<p><input checked=\"\" disabled=\"\" type=\"checkbox\"> done</p>\n</li>\n</ul>\n",
            $renderer->render("- [ ] todo\n\n- [X] done\n"),
        );
    }

    public function testCommonMarkKeepsTaskMarkersLiteral(): void
    {
        self::assertSame(
            "<ul>\n<li>[ ] todo</li>\n<li>[x] done</li>\n</ul>\n",
            new TestHtmlRenderer()->render("- [ ] todo\n- [x] done\n"),
        );
    }
}
