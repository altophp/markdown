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

namespace Alto\Markdown\Extension\CodeBlockTitle;

use Alto\Markdown\Extension\Html\HtmlNodeDecorator;
use Alto\Markdown\Extension\Html\HtmlNodeOutputContext;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CodeBlockTitleDecorator implements HtmlNodeDecorator
{
    public function __construct(private CodeBlockTitlePolicy $policy)
    {
    }

    public function decorate(HtmlNodeOutputContext $context, string $html): string
    {
        $title = CodeBlockTitleInfoParser::title($context->string('info'), $this->policy);
        if (null === $title) {
            return $html;
        }

        $figureAttributes = '' === $this->policy->figureClass
            ? ''
            : ' class="'.$context->escapeAttribute($this->policy->figureClass).'"';
        if ($this->policy->includeDataTitle) {
            $figureAttributes .= ' data-title="'.$context->escapeAttribute($title).'"';
        }
        $captionAttributes = '' === $this->policy->captionClass
            ? ''
            : ' class="'.$context->escapeAttribute($this->policy->captionClass).'"';

        return '<figure'.$figureAttributes.">\n"
            .'<figcaption'.$captionAttributes.'>'.$context->escapeText($title)."</figcaption>\n"
            .$html
            ."</figure>\n";
    }
}
