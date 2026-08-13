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
 * Safe-by-default HTML output policy applied at emission time, on two
 * independent axes.
 *
 * URL policy: an allowlist of schemes checked on link and image
 * destinations (and autolink hrefs). A destination whose scheme is not
 * allowed keeps its element but empties the attribute value; the text
 * content is never dropped. Schemes are detected after control bytes and
 * whitespace (0x00 to 0x20), literal or percent-encoded, are removed,
 * because browsers ignore those inside a URL. A destination with no scheme
 * (relative or scheme-relative) is always allowed.
 *
 * Raw HTML policy: escape (default), strip, or allow. See RawHtmlPolicy.
 * An optional final-fragment sanitizer can preserve a curated HTML subset
 * without exposing parser or document internals.
 *
 * Named constructors cover safe() (the default), curated() (final-fragment
 * allowlist), and spec() (CommonMark passthrough). The renderers branch on the
 * hoisted filtersUrls flag and rawHtml case; only curated mode pays for a DOM
 * parse and serialization.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class HtmlPolicy
{
    /**
     * @var array<string, true>
     */
    private const array SAFE_SCHEMES = [
        'http' => true,
        'https' => true,
        'mailto' => true,
        'tel' => true,
    ];

    private static ?self $safe = null;

    private static ?self $spec = null;

    private static ?self $curated = null;

    private ?string $cacheKey = null;

    /**
     * @param array<string, true> $schemes allowed URL schemes, lowercased
     */
    private function __construct(
        private readonly array $schemes,
        public readonly bool $filtersUrls,
        public readonly RawHtmlPolicy $rawHtml,
        private readonly ?HtmlSanitizer $sanitizer = null,
    ) {}

    /**
     * The default: filter URL schemes to http, https, mailto, tel (plus
     * relative references) and escape raw HTML to visible text.
     */
    public static function safe(): self
    {
        return self::$safe ??= new self(self::SAFE_SCHEMES, true, RawHtmlPolicy::Escape);
    }

    /**
     * CommonMark passthrough: no URL filtering, raw HTML emitted verbatim.
     * Pinned by the conformance runner and the fixture-based render tests.
     */
    public static function spec(): self
    {
        return self::$spec ??= new self([], false, RawHtmlPolicy::Allow);
    }

    /**
     * Preserve a conservative subset of raw HTML after rendering the complete
     * fragment. URLs remain restricted to the safe default schemes.
     */
    public static function curated(): self
    {
        return self::$curated ??= new self(
            self::SAFE_SCHEMES,
            true,
            RawHtmlPolicy::Allow,
            new CuratedHtmlSanitizer(),
        );
    }

    /**
     * @return list<string>
     */
    public function allowedSchemes(): array
    {
        return array_keys($this->schemes);
    }

    /**
     * Replaces the allowed-scheme allowlist and turns URL filtering on.
     */
    public function withAllowedSchemes(string ...$schemes): self
    {
        $map = [];

        foreach ($schemes as $scheme) {
            $map[strtolower($scheme)] = true;
        }

        return new self($map, true, $this->rawHtml, $this->sanitizer);
    }

    /**
     * Adds one scheme to the allowlist and turns URL filtering on.
     */
    public function allowingScheme(string $scheme): self
    {
        return new self($this->schemes + [strtolower($scheme) => true], true, $this->rawHtml, $this->sanitizer);
    }

    public function withRawHtml(RawHtmlPolicy $rawHtml): self
    {
        return new self($this->schemes, $this->filtersUrls, $rawHtml, $this->sanitizer);
    }

    /**
     * Apply a reusable sanitizer to the complete rendered fragment. Raw HTML
     * is passed to that final boundary instead of being escaped first.
     */
    public function withSanitizer(HtmlSanitizer $sanitizer): self
    {
        return new self($this->schemes, $this->filtersUrls, RawHtmlPolicy::Allow, $sanitizer);
    }

    public function sanitizesHtml(): bool
    {
        return null !== $this->sanitizer;
    }

    public function sanitizeHtml(string $html): string
    {
        return null === $this->sanitizer ? $html : $this->sanitizer->sanitize($html, $this);
    }

    /**
     * Stable value fingerprint for namespacing policy-dependent caches.
     * Policies with equal behavior share one key even as distinct instances,
     * so cached output can never be served across differing policies.
     */
    public function cacheKey(): string
    {
        if (null !== $this->cacheKey) {
            return $this->cacheKey;
        }

        $schemes = array_keys($this->schemes);
        sort($schemes);

        $sanitizer = null === $this->sanitizer
            ? ''
            : '|' . get_debug_type($this->sanitizer) . ':' . $this->sanitizer->cacheKey();

        return $this->cacheKey = ($this->filtersUrls ? 'f' : 'p') . '|' . $this->rawHtml->name . '|' . implode(',', $schemes) . $sanitizer;
    }

    /**
     * Whether an emitted (percent-encoded) URL is allowed. Only called
     * when filtersUrls is true.
     */
    public function allowsUrl(string $url): bool
    {
        // Strip control bytes and whitespace (0x00 to 0x20), literal or
        // percent-encoded, so a scheme split by a tab or newline is
        // detected the way a browser would collapse it.
        $probe = (string) preg_replace('/%0[0-9A-Fa-f]|%1[0-9A-Fa-f]|%20|[\x00-\x20]/', '', $url);

        if (1 !== preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $probe, $matches)) {
            // No scheme: a relative or scheme-relative reference is safe.
            return true;
        }

        return isset($this->schemes[strtolower($matches[1])]);
    }
}
