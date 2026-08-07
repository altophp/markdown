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

namespace Alto\Markdown\Extension\Block;

use Alto\Markdown\Parser\ParseOptions;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RawHtmlPolicy;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HtmlBlockOutputContext extends BlockOutputContext
{
    /**
     * @var (\Closure(string, ParseOptions): string)|null
     */
    private ?\Closure $markdownRenderer;

    /**
     * @param (callable(string, ParseOptions): string)|null $markdownRenderer
     *
     * @internal Alto creates output contexts for registered renderers
     */
    public function __construct(
        BlockState $state,
        SourceRange $range,
        string $sourceBytes,
        private HtmlPolicy $policy,
        ?callable $markdownRenderer = null,
    ) {
        parent::__construct($state, $range, $sourceBytes);
        $this->markdownRenderer = null === $markdownRenderer
            ? null
            : $markdownRenderer(...);
    }

    public function escapeText(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    public function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    /**
     * Apply the active URL policy, then escape the value for an HTML
     * attribute. A refused URL becomes an empty string.
     */
    public function escapeUrl(string $value): string
    {
        if ($this->policy->filtersUrls && !$this->policy->allowsUrl($value)) {
            return '';
        }

        return $this->escapeAttribute($value);
    }

    public function allowsRawHtml(): bool
    {
        return RawHtmlPolicy::Allow === $this->policy->rawHtml;
    }

    /**
     * Render a bounded Markdown fragment with the current compiled profile and
     * HTML policy.
     */
    public function renderMarkdown(string $markdown, ParseOptions $options): string
    {
        if (null === $this->markdownRenderer) {
            throw new \LogicException('Nested Markdown rendering is unavailable in this output context.');
        }

        return ($this->markdownRenderer)($markdown, $options);
    }
}
