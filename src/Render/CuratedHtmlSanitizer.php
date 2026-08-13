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

use Alto\Markdown\Exception\RenderException;
use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * A conservative, versioned HTML allowlist for rendered Markdown.
 *
 * This is GitHub-like rather than a byte-for-byte clone of github.com. It
 * keeps common document markup, strips active content and foreign namespaces,
 * validates attributes by element, and reuses HtmlPolicy for URL schemes.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CuratedHtmlSanitizer implements HtmlSanitizer
{
    private const string CACHE_KEY = 'alto-curated-v1';

    private const string HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    /**
     * @var array<string, true>
     */
    private const array ALLOWED_ELEMENTS = [
        'a' => true,
        'abbr' => true,
        'b' => true,
        'bdo' => true,
        'blockquote' => true,
        'br' => true,
        'caption' => true,
        'cite' => true,
        'code' => true,
        'dd' => true,
        'del' => true,
        'details' => true,
        'dfn' => true,
        'div' => true,
        'dl' => true,
        'dt' => true,
        'em' => true,
        'figcaption' => true,
        'figure' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'hr' => true,
        'i' => true,
        'img' => true,
        'input' => true,
        'ins' => true,
        'kbd' => true,
        'li' => true,
        'mark' => true,
        'ol' => true,
        'p' => true,
        'picture' => true,
        'pre' => true,
        'q' => true,
        'rp' => true,
        'rt' => true,
        'ruby' => true,
        's' => true,
        'samp' => true,
        'small' => true,
        'source' => true,
        'span' => true,
        'strike' => true,
        'strong' => true,
        'sub' => true,
        'summary' => true,
        'sup' => true,
        'table' => true,
        'tbody' => true,
        'td' => true,
        'tfoot' => true,
        'th' => true,
        'thead' => true,
        'time' => true,
        'tr' => true,
        'tt' => true,
        'ul' => true,
        'var' => true,
        'wbr' => true,
    ];

    /**
     * Elements whose contents are active, misleading, or unsafe to preserve.
     *
     * @var array<string, true>
     */
    private const array DROP_WITH_CONTENT = [
        'audio' => true,
        'button' => true,
        'canvas' => true,
        'embed' => true,
        'form' => true,
        'iframe' => true,
        'math' => true,
        'noembed' => true,
        'noframes' => true,
        'noscript' => true,
        'object' => true,
        'option' => true,
        'plaintext' => true,
        'script' => true,
        'select' => true,
        'style' => true,
        'svg' => true,
        'template' => true,
        'textarea' => true,
        'title' => true,
        'video' => true,
        'xmp' => true,
    ];

    /**
     * @var array<string, array<string, true>>
     */
    private const array ATTRIBUTES = [
        'a' => [
            'aria-controls' => true,
            'class' => true,
            'href' => true,
            'id' => true,
            'role' => true,
            'title' => true,
        ],
        'abbr' => ['title' => true],
        'bdo' => ['dir' => true],
        'blockquote' => ['cite' => true],
        'code' => ['class' => true],
        'del' => ['cite' => true],
        'details' => ['open' => true],
        'div' => ['aria-labelledby' => true, 'class' => true, 'id' => true, 'role' => true],
        'figcaption' => ['class' => true],
        'figure' => ['class' => true, 'data-title' => true],
        'img' => [
            'alt' => true,
            'height' => true,
            'loading' => true,
            'src' => true,
            'title' => true,
            'width' => true,
        ],
        'input' => ['checked' => true, 'disabled' => true, 'type' => true],
        'ins' => ['cite' => true],
        'li' => ['id' => true, 'role' => true, 'value' => true],
        'ol' => ['reversed' => true, 'start' => true, 'type' => true],
        'p' => ['class' => true],
        'q' => ['cite' => true],
        'sup' => ['id' => true],
        'td' => ['align' => true, 'colspan' => true, 'rowspan' => true],
        'th' => ['align' => true, 'colspan' => true, 'rowspan' => true, 'scope' => true],
        'time' => ['datetime' => true],
    ];

    public function sanitize(string $html, HtmlPolicy $policy): string
    {
        if (!class_exists(HTMLDocument::class)) {
            throw new RenderException('Curated HTML rendering requires the PHP DOM extension.');
        }

        $document = HTMLDocument::createFromString(
            '<!doctype html><html><body><div data-alto-sanitizer-root="">' . $html . '</div></body></html>',
            \LIBXML_NOERROR | \LIBXML_COMPACT,
            'UTF-8',
        );
        $body = $document->querySelector('body');
        $root = $document->querySelector('[data-alto-sanitizer-root]');

        if (!$body instanceof Element || !$root instanceof Element) {
            throw new RenderException('Unable to create the curated HTML sanitization context.');
        }

        $this->unwrap($root);

        foreach ($this->children($body) as $child) {
            $this->sanitizeNode($child, $policy);
        }

        $result = '';

        foreach ($this->children($body) as $child) {
            $result .= $document->saveHtml($child);
        }

        return $result;
    }

    public function cacheKey(): string
    {
        return self::CACHE_KEY;
    }

    private function sanitizeNode(Node $node, HtmlPolicy $policy): void
    {
        if ($node instanceof Text) {
            return;
        }

        if ($node instanceof Comment) {
            $node->remove();

            return;
        }

        if (!$node instanceof Element) {
            $node->parentNode?->removeChild($node);

            return;
        }

        $name = strtolower($node->localName);

        if (self::HTML_NAMESPACE !== $node->namespaceURI || isset(self::DROP_WITH_CONTENT[$name])) {
            $node->remove();

            return;
        }

        if (!isset(self::ALLOWED_ELEMENTS[$name])) {
            foreach ($this->children($node) as $child) {
                $this->sanitizeNode($child, $policy);
            }

            $this->unwrap($node);

            return;
        }

        if ('input' === $name) {
            $type = $node->getAttribute('type');

            if (null === $type || 'checkbox' !== strtolower($type)) {
                $node->remove();

                return;
            }
        }

        $this->sanitizeAttributes($node, $name, $policy);

        if ('input' === $name) {
            $node->setAttribute('type', 'checkbox');
            $node->setAttribute('disabled', '');
        }

        foreach ($this->children($node) as $child) {
            $this->sanitizeNode($child, $policy);
        }
    }

    private function sanitizeAttributes(Element $element, string $elementName, HtmlPolicy $policy): void
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute;
        }

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            if (null !== $attribute->namespaceURI || !$this->allowsAttribute($elementName, $name)) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            $normalized = $this->attributeValue($elementName, $name, $value, $policy);

            if (null === $normalized) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if ($normalized !== $value) {
                $element->setAttribute($name, $normalized);
            }
        }
    }

    private function allowsAttribute(string $element, string $attribute): bool
    {
        if ('title' === $attribute || 'lang' === $attribute || 'dir' === $attribute) {
            return true;
        }

        return isset(self::ATTRIBUTES[$element][$attribute]);
    }

    private function attributeValue(
        string $element,
        string $attribute,
        string $value,
        HtmlPolicy $policy,
    ): ?string {
        return match ($attribute) {
            'href' => $this->allowsLinkUrl($value, $policy) ? $value : null,
            'src', 'cite' => $this->allowsResourceUrl($value, $policy) ? $value : null,
            'class' => $this->classValue($element, $value),
            'id' => $this->idValue($value),
            'aria-controls', 'aria-labelledby' => $this->tabsId($value) ? $value : null,
            'role' => $this->roleValue($element, $value),
            'dir' => \in_array(strtolower($value), ['ltr', 'rtl', 'auto'], true) ? strtolower($value) : null,
            'align' => \in_array(strtolower($value), ['left', 'center', 'right'], true) ? strtolower($value) : null,
            'scope' => \in_array(strtolower($value), ['row', 'col', 'rowgroup', 'colgroup'], true) ? strtolower($value) : null,
            'loading' => \in_array(strtolower($value), ['lazy', 'eager'], true) ? strtolower($value) : null,
            'type' => $this->typeValue($element, $value),
            'start', 'value' => 1 === preg_match('/^-?[0-9]{1,9}$/', $value) ? $value : null,
            'colspan', 'rowspan' => $this->boundedInteger($value, 1, 1000),
            'width', 'height' => $this->boundedInteger($value, 1, 100000),
            'lang' => 1 === preg_match('/^[A-Za-z0-9-]{1,64}$/', $value) ? $value : null,
            'open', 'checked', 'disabled', 'reversed' => '',
            'datetime' => \strlen($value) <= 128 ? $value : null,
            'data-title' => \strlen($value) <= 512 ? $value : null,
            'title', 'alt' => $value,
            default => null,
        };
    }

    private function allowsLinkUrl(string $url, HtmlPolicy $policy): bool
    {
        if ($policy->filtersUrls) {
            return $policy->allowsUrl($url);
        }

        $scheme = $this->urlScheme($url);

        return null === $scheme || \in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    private function allowsResourceUrl(string $url, HtmlPolicy $policy): bool
    {
        if ($policy->filtersUrls && !$policy->allowsUrl($url)) {
            return false;
        }

        $scheme = $this->urlScheme($url);

        return null === $scheme || \in_array($scheme, ['http', 'https'], true);
    }

    private function urlScheme(string $url): ?string
    {
        $probe = (string) preg_replace('/%0[0-9A-Fa-f]|%1[0-9A-Fa-f]|%20|[\x00-\x20]/', '', $url);

        if (1 !== preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $probe, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function classValue(string $element, string $value): ?string
    {
        $tokens = preg_split('/\s+/', trim($value), -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $tokens) {
            return null;
        }

        $allowed = [];

        foreach ($tokens as $token) {
            if (
                ('code' === $element && 1 === preg_match('/^language-[A-Za-z0-9_.+-]+$/', $token))
                || ('a' === $element && \in_array($token, ['markdown-tabs-tab', 'is-active'], true))
                || ('div' === $element && (
                    'footnotes' === $token
                    || \in_array($token, [
                        'markdown-tabs',
                        'markdown-tabs-list',
                        'markdown-tabs-panels',
                        'markdown-tabs-panel',
                        'is-active',
                    ], true)
                    || 1 === preg_match('/^markdown-alert(?:-(?:note|tip|important|warning|caution))?$/', $token)
                ))
                || ('figure' === $element && \in_array($token, ['code-block', 'has-title'], true))
                || ('figcaption' === $element && 'code-title' === $token)
                || ('p' === $element && 'markdown-alert-title' === $token)
            ) {
                $allowed[$token] = true;
            }
        }

        return [] === $allowed ? null : implode(' ', array_keys($allowed));
    }

    private function roleValue(string $element, string $value): ?string
    {
        return match ($element) {
            'a' => \in_array($value, ['doc-noteref', 'doc-backlink'], true) ? $value : null,
            'div' => 'doc-endnotes' === $value ? $value : null,
            'li' => 'doc-endnote' === $value ? $value : null,
            default => null,
        };
    }

    private function idValue(string $value): ?string
    {
        return 1 === preg_match('/^fn(?:ref)?-[1-9][0-9]{0,8}(?:-[1-9][0-9]{0,8})?$/', $value)
            || $this->tabsId($value)
            ? $value
            : null;
    }

    private function tabsId(string $value): bool
    {
        return 1 === preg_match(
            '/^markdown-tabs-[1-9][0-9]{0,8}(?:-(?:tab|panel)-[1-9][0-9]{0,8})?$/',
            $value,
        );
    }

    private function typeValue(string $element, string $value): ?string
    {
        $value = strtolower($value);

        if ('input' === $element) {
            return 'checkbox' === $value ? $value : null;
        }

        if ('ol' === $element && \in_array($value, ['1', 'a', 'i'], true)) {
            return $value;
        }

        return null;
    }

    private function boundedInteger(string $value, int $minimum, int $maximum): ?string
    {
        if (1 !== preg_match('/^[0-9]{1,9}$/', $value)) {
            return null;
        }

        $number = (int) $value;

        return $number >= $minimum && $number <= $maximum ? (string) $number : null;
    }

    /**
     * @return list<Node>
     */
    private function children(Node $node): array
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        return $children;
    }

    private function unwrap(Element $element): void
    {
        while (null !== $element->firstChild) {
            $element->before($element->firstChild);
        }

        $element->remove();
    }
}
