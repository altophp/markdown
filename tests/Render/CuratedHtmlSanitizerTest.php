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

use Alto\Markdown\Render\CuratedHtmlSanitizer;
use Alto\Markdown\Render\HtmlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CuratedHtmlSanitizerTest extends TestCase
{
    public function testCommonDocumentMarkupIsPreserved(): void
    {
        $html = <<<'HTML'
            <h1 title="Guide">Guide</h1>
            <blockquote cite="https://example.com/source"><p><strong>Safe</strong> <em>HTML</em>.</p></blockquote>
            <table><thead><tr><th align="left" scope="col">Name</th></tr></thead><tbody><tr><td colspan="2">Value</td></tr></tbody></table>
            <details open=""><summary>More</summary><p>Details.</p></details>
            HTML;

        self::assertSame($html, $this->sanitize($html));
    }

    public function testActiveElementsAndForeignNamespacesAreDroppedWithTheirContents(): void
    {
        $html = $this->sanitize(
            '<p>before</p>'
            . '<script><img src=x onerror=alert(1)></script>'
            . '<style>body{display:none}</style>'
            . '<iframe srcdoc="<script>alert(1)</script>">fallback</iframe>'
            . '<svg><a href="javascript:alert(1)"><circle onload="alert(1)"></circle></a></svg>'
            . '<math><mi onclick="alert(1)">x</mi></math>'
            . '<p>after</p>',
        );

        self::assertSame('<p>before</p><p>after</p>', $html);
    }

    public function testUnknownElementsAreUnwrappedAfterTheirChildrenAreSanitized(): void
    {
        self::assertSame(
            '<strong>kept</strong>tail',
            $this->sanitize('<custom onclick="alert(1)"><strong>kept</strong><script>bad</script>tail</custom>'),
        );
    }

    public function testAttributesAreAllowedByElementAndNormalized(): void
    {
        $html = $this->sanitize(
            '<div id="x" name="y" style="color:red" onclick="alert(1)" '
            . 'class="evil markdown-alert markdown-alert-warning">'
            . '<p class="markdown-alert-title evil" dir="RTL" lang="fr-FR">Warning</p>'
            . '<code class="evil language-php language-c++">code</code>'
            . '</div>',
        );

        self::assertSame(
            '<div class="markdown-alert markdown-alert-warning">'
            . '<p class="markdown-alert-title" dir="rtl" lang="fr-FR">Warning</p>'
            . '<code class="language-php language-c++">code</code>'
            . '</div>',
            $html,
        );
    }

    public function testGeneratedTabMarkupIsPreservedWithoutBroadeningIdentifiers(): void
    {
        self::assertSame(
            '<div class="markdown-tabs" id="markdown-tabs-1">'
            . '<div class="markdown-tabs-list">'
            . '<a class="markdown-tabs-tab is-active" id="markdown-tabs-1-tab-1"'
            . ' href="#markdown-tabs-1-panel-1" aria-controls="markdown-tabs-1-panel-1">PHP</a>'
            . '</div><div class="markdown-tabs-panels">'
            . '<div class="markdown-tabs-panel is-active" id="markdown-tabs-1-panel-1"'
            . ' aria-labelledby="markdown-tabs-1-tab-1"><p>Body</p></div>'
            . '</div></div>'
            . '<div><a href="#tabs-unsafe">Unsafe</a></div>',
            $this->sanitize(
                '<div class="markdown-tabs evil" id="markdown-tabs-1">'
                . '<div class="markdown-tabs-list">'
                . '<a class="markdown-tabs-tab is-active" id="markdown-tabs-1-tab-1"'
                . ' href="#markdown-tabs-1-panel-1" aria-controls="markdown-tabs-1-panel-1">PHP</a>'
                . '</div><div class="markdown-tabs-panels">'
                . '<div class="markdown-tabs-panel is-active" id="markdown-tabs-1-panel-1"'
                . ' aria-labelledby="markdown-tabs-1-tab-1"><p>Body</p></div>'
                . '</div></div>'
                . '<div id="tabs-unsafe"><a id="tabs-unsafe" href="#tabs-unsafe"'
                . ' aria-controls="tabs-unsafe">Unsafe</a></div>',
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function links(): iterable
    {
        yield 'https' => ['https://example.com/path', '<a href="https://example.com/path">link</a>'];
        yield 'relative' => ['/docs/page', '<a href="/docs/page">link</a>'];
        yield 'fragment' => ['#section', '<a href="#section">link</a>'];
        yield 'mailto' => ['mailto:user@example.com', '<a href="mailto:user@example.com">link</a>'];
        yield 'javascript' => ['javascript:alert(1)', '<a>link</a>'];
        yield 'encoded control' => ['java&#x09;script:alert(1)', '<a>link</a>'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', '<a>link</a>'];
    }

    #[DataProvider('links')]
    public function testLinkUrlsUseThePolicyAllowlist(string $url, string $expected): void
    {
        self::assertSame($expected, $this->sanitize('<a href="' . $url . '">link</a>'));
    }

    public function testResourceUrlsUseAStricterProtocolSet(): void
    {
        self::assertSame(
            '<img alt="mail"><img alt="script"><img src="/image.png" alt="relative">'
            . '<img src="https://example.com/image.png" alt="web">',
            $this->sanitize(
                '<img src="mailto:user@example.com" alt="mail">'
                . '<img src="javascript:alert(1)" alt="script">'
                . '<img src="/image.png" alt="relative">'
                . '<img src="https://example.com/image.png" alt="web" onerror="alert(1)">',
            ),
        );
    }

    public function testDirectUseStillRestrictsUrlsWithTheSpecPolicy(): void
    {
        self::assertSame(
            '<a>unsafe</a><a href="mailto:user@example.com">mail</a><img src="https://example.com/image.png">',
            $this->sanitize(
                '<a href="javascript:alert(1)">unsafe</a>'
                . '<a href="mailto:user@example.com">mail</a>'
                . '<img src="https://example.com/image.png">',
                HtmlPolicy::spec(),
            ),
        );
    }

    public function testCheckboxesAreTheOnlyPreservedInputsAndAreForcedDisabled(): void
    {
        self::assertSame(
            '<input type="checkbox" checked="" disabled="">',
            $this->sanitize(
                '<input type="text" value="misleading">'
                . '<input type="checkbox" checked name="field" onclick="alert(1)">',
            ),
        );
    }

    public function testInvalidStructuredAttributeValuesAreRemoved(): void
    {
        self::assertSame(
            '<ol><li>Item</li></ol><ol type="a"><li>Typed</li></ol>'
            . '<table><tbody><tr><td>Cell</td></tr></tbody></table><img>'
            . '<time datetime="2026-07-26">Today</time>',
            $this->sanitize(
                '<ol start="1e9" type="x"><li value="huge">Item</li></ol>'
                . '<ol type="A"><li>Typed</li></ol>'
                . '<table><tr><td colspan="0" rowspan="999999">Cell</td></tr></table>'
                . '<img width="-1" height="999999999" loading="now">'
                . '<time datetime="2026-07-26">Today</time>',
            ),
        );
    }

    public function testCommentsAndWrapperBreakoutDoNotEscapeSanitization(): void
    {
        self::assertSame(
            '<p>before</p><p>after</p>',
            $this->sanitize('<p>before</p><!-- hidden --></div><script>bad</script><p>after</p>'),
        );
    }

    public function testMalformedNamespaceConfusionVectorIsNeutralized(): void
    {
        $html = $this->sanitize(
            '<math><mtext><table><mglyph><style><!--</style>'
            . '<img title="--><img src=1 onerror=alert(1)>"></table></mtext></math>'
            . '<p>safe</p>',
        );

        self::assertSame('<p>safe</p>', $html);
    }

    public function testSanitizationIsIdempotent(): void
    {
        $once = $this->sanitize(
            '<DIV onclick=x><custom><strong>safe</strong></custom>'
            . '<a href="https://example.com?a=1&amp;b=2">link</a></DIV>',
        );

        self::assertSame($once, $this->sanitize($once));
    }

    private function sanitize(string $html, ?HtmlPolicy $policy = null): string
    {
        return new CuratedHtmlSanitizer()->sanitize($html, $policy ?? HtmlPolicy::curated());
    }
}
