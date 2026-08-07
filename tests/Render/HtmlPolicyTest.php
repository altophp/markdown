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

use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RawHtmlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlPolicyTest extends TestCase
{
    public function testSafePolicyHasStableDefaults(): void
    {
        $policy = HtmlPolicy::safe();

        self::assertSame($policy, HtmlPolicy::safe());
        self::assertTrue($policy->filtersUrls);
        self::assertSame(RawHtmlPolicy::Escape, $policy->rawHtml);
        self::assertSame(['http', 'https', 'mailto', 'tel'], $policy->allowedSchemes());
        self::assertSame('f|Escape|http,https,mailto,tel', $policy->cacheKey());
        self::assertSame($policy->cacheKey(), $policy->cacheKey());
    }

    public function testSpecPolicyHasStableDefaults(): void
    {
        $policy = HtmlPolicy::spec();

        self::assertSame($policy, HtmlPolicy::spec());
        self::assertFalse($policy->filtersUrls);
        self::assertSame(RawHtmlPolicy::Allow, $policy->rawHtml);
        self::assertSame([], $policy->allowedSchemes());
        self::assertSame('p|Allow|', $policy->cacheKey());
    }

    public function testCuratedPolicyHasStableDefaults(): void
    {
        $policy = HtmlPolicy::curated();

        self::assertSame($policy, HtmlPolicy::curated());
        self::assertTrue($policy->filtersUrls);
        self::assertSame(RawHtmlPolicy::Allow, $policy->rawHtml);
        self::assertSame(['http', 'https', 'mailto', 'tel'], $policy->allowedSchemes());
        self::assertTrue($policy->sanitizesHtml());
        self::assertSame(
            'f|Allow|http,https,mailto,tel|Alto\\Markdown\\Render\\CuratedHtmlSanitizer:alto-curated-v1',
            $policy->cacheKey(),
        );
    }

    public function testAllowedSchemesAreReplacedAndNormalized(): void
    {
        $policy = HtmlPolicy::spec()->withAllowedSchemes('HTTPS', 'custom', 'https');

        self::assertTrue($policy->filtersUrls);
        self::assertSame(RawHtmlPolicy::Allow, $policy->rawHtml);
        self::assertSame(['https', 'custom'], $policy->allowedSchemes());
        self::assertTrue($policy->allowsUrl('HTTPS://example.com'));
        self::assertTrue($policy->allowsUrl('custom:value'));
        self::assertFalse($policy->allowsUrl('http://example.com'));
        self::assertSame('f|Allow|custom,https', $policy->cacheKey());
    }

    public function testSchemeCanBeAddedWithoutMutatingTheOriginalPolicy(): void
    {
        $original = HtmlPolicy::spec();
        $policy = $original->allowingScheme('CuStOm');

        self::assertFalse($original->filtersUrls);
        self::assertSame([], $original->allowedSchemes());
        self::assertTrue($policy->filtersUrls);
        self::assertSame(['custom'], $policy->allowedSchemes());
        self::assertTrue($policy->allowsUrl('CUSTOM:value'));
        self::assertFalse($policy->allowsUrl('https://example.com'));
    }

    public function testRawHtmlModeChangesWithoutChangingTheUrlPolicy(): void
    {
        $original = HtmlPolicy::safe();
        $policy = $original->withRawHtml(RawHtmlPolicy::Strip);

        self::assertSame(RawHtmlPolicy::Escape, $original->rawHtml);
        self::assertSame(RawHtmlPolicy::Strip, $policy->rawHtml);
        self::assertSame($original->filtersUrls, $policy->filtersUrls);
        self::assertSame($original->allowedSchemes(), $policy->allowedSchemes());
        self::assertSame('f|Strip|http,https,mailto,tel', $policy->cacheKey());
    }

    public function testRawHtmlModeAndFinalSanitizerRemainIndependent(): void
    {
        $policy = HtmlPolicy::curated()->withRawHtml(RawHtmlPolicy::Escape);

        self::assertTrue($policy->sanitizesHtml());
        self::assertSame(
            'f|Escape|http,https,mailto,tel|Alto\\Markdown\\Render\\CuratedHtmlSanitizer:alto-curated-v1',
            $policy->cacheKey(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'relative path' => ['/docs/page'];
        yield 'relative name' => ['page.html'];
        yield 'fragment' => ['#section'];
        yield 'scheme relative' => ['//example.com/page'];
        yield 'http' => ['http://example.com'];
        yield 'mixed case https' => ['HTTPS://example.com'];
        yield 'mailto' => ['mailto:user@example.com'];
        yield 'tel' => ['tel:+33123456789'];
        yield 'percent encoded first letter' => ['%6aavascript:alert(1)'];
        yield 'non alphabetic prefix' => ['1javascript:alert(1)'];
    }

    #[DataProvider('allowedUrls')]
    public function testSafePolicyAllowsExpectedUrlForms(string $url): void
    {
        self::assertTrue(HtmlPolicy::safe()->allowsUrl($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'mixed case javascript' => ['JaVaScRiPt:alert(1)'];
        yield 'data' => ['data:text/html,alert(1)'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'literal tab inside scheme' => ["java\tscript:alert(1)"];
        yield 'literal control byte inside scheme' => ["java\x1Fscript:alert(1)"];
        yield 'encoded tab inside scheme' => ['java%09script:alert(1)'];
        yield 'encoded newline inside scheme' => ['java%0Ascript:alert(1)'];
        yield 'encoded space before scheme' => ['%20javascript:alert(1)'];
    }

    #[DataProvider('blockedUrls')]
    public function testSafePolicyRejectsUnsafeSchemesAndBrowserWhitespaceEvasions(string $url): void
    {
        self::assertFalse(HtmlPolicy::safe()->allowsUrl($url));
    }
}
