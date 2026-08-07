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

namespace Alto\Markdown\Tests\Render;

use Alto\Markdown\Markdown;
use PHPUnit\Framework\TestCase;

final class HtmlTableFastPathTest extends TestCase
{
    public function testTableHtmlUnchangedAcrossPlainAndRichCells(): void
    {
        $md = "| a | b | c |\n| --- | --- | --- |\n| 42 | a *b* | www.x.co |\n| \"q\" > r | AT&amp;T | plain |\n";

        // Hardcoded expected HTML for the markdown above, captured once from a correct render.
        $expected = <<<'HTML'
            <table>
            <thead>
            <tr>
            <th>a</th>
            <th>b</th>
            <th>c</th>
            </tr>
            </thead>
            <tbody>
            <tr>
            <td>42</td>
            <td>a <em>b</em></td>
            <td><a href="http://www.x.co">www.x.co</a></td>
            </tr>
            <tr>
            <td>&quot;q&quot; &gt; r</td>
            <td>AT&amp;T</td>
            <td>plain</td>
            </tr>
            </tbody>
            </table>

            HTML;

        $html = Markdown::gfm()->fromString($md)->toHtml();

        self::assertSame($expected, $html);
        self::assertStringContainsString('<td>42</td>', $html);
        self::assertStringContainsString('<td>&quot;q&quot; &gt; r</td>', $html);
    }
}
