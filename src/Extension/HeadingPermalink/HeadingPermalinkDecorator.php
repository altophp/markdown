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

namespace Alto\Markdown\Extension\HeadingPermalink;

use Alto\Markdown\Extension\Html\HtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HeadingPermalinkDecorator implements HtmlNodeDecorator
{
    public function __construct(private HeadingPermalinkPolicy $policy) {}

    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        $level = $context->int('level');
        if (!$this->policy->appliesTo($level)) {
            return $html;
        }

        $open = '<h' . $level;
        $openAt = stripos($html, $open);
        if (false === $openAt) {
            return $html;
        }

        $openEnd = strpos($html, '>', $openAt + \strlen($open));
        $closeAt = strripos($html, '</h' . $level . '>');
        if (false === $openEnd || false === $closeAt || $closeAt < $openEnd) {
            return $html;
        }

        $slug = $context->string('slug');
        $headingAttributes = [];
        if ($this->policy->applyIdToHeading) {
            $headingAttributes[] = 'id="' . $context->escapeAttribute(HeadingPermalinkPolicy::prefixed($this->policy->idPrefix, $slug)) . '"';
        }
        if ('' !== $this->policy->headingClass) {
            $headingAttributes[] = 'class="' . $context->escapeAttribute($this->policy->headingClass) . '"';
        }

        if ([] !== $headingAttributes) {
            $insert = $openAt + \strlen($open);
            $attributes = ' ' . implode(' ', $headingAttributes);
            $html = substr($html, 0, $insert) . $attributes . substr($html, $insert);
            $openEnd += \strlen($attributes);
            $closeAt += \strlen($attributes);
        }

        if (HeadingPermalinkPosition::None === $this->policy->position) {
            return $html;
        }

        $anchorAttributes = [];
        if (!$this->policy->applyIdToHeading) {
            $anchorAttributes[] = 'id="' . $context->escapeAttribute(HeadingPermalinkPolicy::prefixed($this->policy->idPrefix, $slug)) . '"';
        }
        $anchorAttributes[] = 'href="#' . $context->escapeAttribute(HeadingPermalinkPolicy::prefixed($this->policy->fragmentPrefix, $slug)) . '"';
        if ('' !== $this->policy->htmlClass) {
            $anchorAttributes[] = 'class="' . $context->escapeAttribute($this->policy->htmlClass) . '"';
        }
        if ($this->policy->ariaHidden) {
            $anchorAttributes[] = 'aria-hidden="true"';
        }
        $anchorAttributes[] = 'title="' . $context->escapeAttribute($this->policy->title) . '"';
        $anchor = '<a ' . implode(' ', $anchorAttributes) . '>'
            . $context->escapeText($this->policy->symbol)
            . '</a>';
        $insert = HeadingPermalinkPosition::Before === $this->policy->position
            ? $openEnd + 1
            : $closeAt;

        return substr($html, 0, $insert) . $anchor . substr($html, $insert);
    }
}
