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

namespace Alto\Markdown\Extension\ExternalLink;

use Alto\Markdown\Extension\Html\HtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ExternalLinkDecorator implements HtmlNodeDecorator
{
    public function __construct(
        private ExternalLinkPolicy $policy,
    ) {
    }

    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        $host = parse_url($context->string('destination'), \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return $html;
        }

        $external = !$this->policy->isInternalHost($host);
        $attributes = [];

        if ($external && '' !== $this->policy->htmlClass) {
            $attributes[] = 'class="'.$context->escapeAttribute($this->policy->htmlClass).'"';
        }

        $rel = [];
        foreach ([
            'nofollow' => $this->policy->nofollow,
            'noopener' => $this->policy->noopener,
            'noreferrer' => $this->policy->noreferrer,
        ] as $token => $scope) {
            if ($scope->applies($external)) {
                $rel[] = $token;
            }
        }

        if ([] !== $rel) {
            $attributes[] = 'rel="'.implode(' ', $rel).'"';
        }

        if ($external && $this->policy->openInNewWindow) {
            $attributes[] = 'target="_blank"';
        }

        if ([] === $attributes) {
            return $html;
        }

        if (1 !== preg_match('/<a(?=[\\s>])/i', $html, $match, \PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $insert = $match[0][1] + 2;

        return substr($html, 0, $insert).' '.implode(' ', $attributes).substr($html, $insert);
    }
}
