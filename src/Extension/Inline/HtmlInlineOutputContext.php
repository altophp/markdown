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

namespace Alto\Markdown\Extension\Inline;

use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HtmlInlineOutputContext extends InlineOutputContext
{
    /**
     * @internal Alto creates output contexts for registered renderers
     */
    public function __construct(
        InlineNode $node,
        ?SourceRange $range,
        string $source,
        private HtmlPolicy $policy,
    ) {
        parent::__construct($node, $range, $source);
    }

    public function escapeText(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    public function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    public function escapeUrl(string $value): string
    {
        if ($this->policy->filtersUrls && !$this->policy->allowsUrl($value)) {
            return '';
        }

        return $this->escapeAttribute($value);
    }
}
