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
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RawHtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * XSS corpus for the safe-by-default HTML policy. Every vector is rendered
 * through both lanes (direct string conversion and the document workspace)
 * and the two outputs must be byte-identical: a policy divergence between
 * lanes is a bug. The corpus covers unsafe URL schemes and their evasions,
 * title-attribute injection, and raw HTML under each raw-HTML mode.
 */
final class HtmlPolicyXssTest extends TestCase
{
    /**
     * Disallowed URL schemes: the link keeps its text but the href is
     * emptied. Covers case variants, scriptable schemes, data URIs,
     * entity-encoded schemes, and control-byte splits (browsers ignore
     * 0x00 to 0x20 inside a URL, so a tab- or newline-split scheme is the
     * scheme it spells once collapsed).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function blockedLinks(): iterable
    {
        yield 'javascript lowercase' => ['[x](javascript:alert(1))', 'x'];
        yield 'javascript mixed case' => ['[x](JavaScript:alert(1))', 'x'];
        yield 'javascript upper case' => ['[x](JAVASCRIPT:alert(1))', 'x'];
        yield 'vbscript' => ['[x](vbscript:msgbox(1))', 'x'];
        yield 'data text/html' => ['[x](data:text/html,foo)', 'x'];
        yield 'file scheme' => ['[x](file:///etc/passwd)', 'x'];
        yield 'entity-encoded j' => ['[x](&#106;avascript:alert(1))', 'x'];
        yield 'hex-entity-encoded j' => ['[x](&#x6a;avascript:alert(1))', 'x'];
        yield 'tab-split scheme' => ['[x](<jav&#9;ascript:alert(1)>)', 'x'];
        yield 'newline-split scheme' => ['[x](<jav&#10;ascript:alert(1)>)', 'x'];
        yield 'cr-split scheme' => ['[x](<jav&#13;ascript:alert(1)>)', 'x'];
        yield 'leading-space scheme' => ['[x](<&#32;javascript:alert(1)>)', 'x'];
    }

    #[DataProvider('blockedLinks')]
    public function testDisallowedSchemesEmptyTheHref(string $markdown, string $text): void
    {
        $html = $this->both($markdown, HtmlPolicy::safe());

        self::assertSame(\sprintf("<p><a href=\"\">%s</a></p>\n", $text), $html);
    }

    /**
     * Safe destinations pass unchanged: the allowlisted schemes plus
     * relative, scheme-relative, and fragment references.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function allowedLinks(): iterable
    {
        yield 'https' => ['[x](https://ok.example/a)', 'https://ok.example/a'];
        yield 'http' => ['[x](http://ok.example)', 'http://ok.example'];
        yield 'mailto' => ['[x](mailto:a@b.example)', 'mailto:a@b.example'];
        yield 'tel' => ['[x](tel:+15551234567)', 'tel:+15551234567'];
        yield 'relative path' => ['[x](/docs/page)', '/docs/page'];
        yield 'relative bare' => ['[x](page.html)', 'page.html'];
        yield 'fragment' => ['[x](#section)', '#section'];
        yield 'scheme-relative' => ['[x](//evil.example/x)', '//evil.example/x'];
        // Percent-encoded scheme bytes are not a scheme to a browser (it
        // does not decode before scheme detection), so this stays a safe
        // relative reference rather than being misread as javascript:.
        yield 'percent-encoded pseudo-scheme' => ['[x](%6aavascript:alert(1))', '%6aavascript:alert(1)'];
    }

    #[DataProvider('allowedLinks')]
    public function testAllowedSchemesArePreserved(string $markdown, string $href): void
    {
        $html = $this->both($markdown, HtmlPolicy::safe());

        self::assertSame(\sprintf("<p><a href=\"%s\">x</a></p>\n", $href), $html);
    }

    public function testImageSrcIsFilteredButAltIsKept(): void
    {
        $html = $this->both('![the alt](javascript:alert(1))', HtmlPolicy::safe());

        self::assertSame("<p><img src=\"\" alt=\"the alt\" /></p>\n", $html);
    }

    public function testSafeImageSrcIsPreserved(): void
    {
        $html = $this->both('![pic](https://ok.example/a.png)', HtmlPolicy::safe());

        self::assertSame("<p><img src=\"https://ok.example/a.png\" alt=\"pic\" /></p>\n", $html);
    }

    public function testAutolinkWithUnsafeSchemeIsFiltered(): void
    {
        $html = $this->both('<javascript:alert(1)>', HtmlPolicy::safe());

        self::assertSame("<p><a href=\"\">javascript:alert(1)</a></p>\n", $html);
    }

    public function testAutolinkWithSafeSchemeIsPreserved(): void
    {
        $html = $this->both('<https://ok.example>', HtmlPolicy::safe());

        self::assertSame("<p><a href=\"https://ok.example\">https://ok.example</a></p>\n", $html);
    }

    public function testTitleAttributeInjectionIsNeutralisedByEscaping(): void
    {
        $html = $this->both('[x](https://ok.example \'a" onmouseover="alert(1)\')', HtmlPolicy::safe());

        self::assertSame(
            "<p><a href=\"https://ok.example\" title=\"a&quot; onmouseover=&quot;alert(1)\">x</a></p>\n",
            $html,
        );
        self::assertStringNotContainsString('onmouseover="', $html);
    }

    /**
     * Raw HTML vectors under each raw-HTML mode. The safe default escapes
     * them to visible text; strip removes them; allow (spec) passes them
     * through.
     *
     * @return iterable<string, array{string}>
     */
    public static function rawHtmlVectors(): iterable
    {
        yield 'inline script' => ['a <script>alert(1)</script> b'];
        yield 'inline iframe' => ['a <iframe src="javascript:alert(1)"></iframe> b'];
        yield 'inline div onclick' => ['a <div onclick="alert(1)">x</div> b'];
        yield 'inline img onerror' => ['a <img src=x onerror="alert(1)"> b'];
        yield 'inline svg onload' => ['a <svg onload="alert(1)"></svg> b'];
    }

    #[DataProvider('rawHtmlVectors')]
    public function testRawInlineHtmlIsEscapedByDefault(string $markdown): void
    {
        $html = $this->both($markdown, HtmlPolicy::safe());

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('<div', $html);
        self::assertStringContainsString('&lt;', $html);
    }

    #[DataProvider('rawHtmlVectors')]
    public function testRawInlineHtmlIsStripped(string $markdown): void
    {
        $html = $this->both($markdown, HtmlPolicy::safe()->withRawHtml(RawHtmlPolicy::Strip));

        // The tags and their event-handler attributes are gone; only the
        // surrounding and inner text remains.
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('<div', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('onload', $html);
    }

    public function testRawInlineHtmlPassesThroughUnderSpec(): void
    {
        // A tag outside the GFM tagfilter set survives verbatim.
        $html = $this->both('a <div onclick="alert(1)">x</div> b', HtmlPolicy::spec());

        self::assertSame("<p>a <div onclick=\"alert(1)\">x</div> b</p>\n", $html);
    }

    public function testRawHtmlBlockEscapedByDefault(): void
    {
        $html = $this->both('<div onclick="alert(1)">hi</div>', HtmlPolicy::safe());

        self::assertStringNotContainsString('<div', $html);
        self::assertStringContainsString('&lt;div', $html);
    }

    public function testRawHtmlBlockStripped(): void
    {
        $html = $this->both('<div onclick="alert(1)">hi</div>', HtmlPolicy::safe()->withRawHtml(RawHtmlPolicy::Strip));

        self::assertSame('', $html);
    }

    public function testRawHtmlBlockPassthroughUnderSpec(): void
    {
        $html = $this->both("<div onclick=\"alert(1)\">hi</div>\n", HtmlPolicy::spec());

        self::assertSame("<div onclick=\"alert(1)\">hi</div>\n", $html);
    }

    public function testTagFilterStillActsUnderSpecMode(): void
    {
        // In passthrough mode the GFM tagfilter neutralises the nine
        // dangerous tags; the safety mechanism is the policy, the
        // tagfilter is a spec-mode behaviour that stays intact.
        $html = $this->both('a <script>x</script> b', HtmlPolicy::spec());

        self::assertSame("<p>a &lt;script>x&lt;/script> b</p>\n", $html);
    }

    public function testSpecModeRestoresUnsafeUrlPassthrough(): void
    {
        $html = $this->both('[x](javascript:alert(1))', HtmlPolicy::spec());

        self::assertSame("<p><a href=\"javascript:alert(1)\">x</a></p>\n", $html);
    }

    /**
     * Renders one markdown string through both lanes under the given
     * policy, asserts the outputs are byte-identical, and returns the
     * shared output.
     */
    private function both(string $markdown, HtmlPolicy $policy, string $profile = 'gfm'): string
    {
        $factory = self::factory($profile);
        $options = new RenderOptions(htmlPolicy: $policy);

        $direct = $factory->toHtml($markdown, renderOptions: $options);
        $document = $factory->fromString($markdown)->toHtml($options);

        self::assertSame($document, $direct, 'Direct and document lanes diverge for: ' . $markdown);

        return $direct;
    }

    private static function factory(string $profile): MarkdownFactory
    {
        return match ($profile) {
            'commonmark' => Markdown::commonmark(),
            'github' => Markdown::github(),
            default => Markdown::gfm(),
        };
    }
}
