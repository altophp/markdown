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

namespace Alto\Markdown\Extension\Mention;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class UrlTemplateMentionResolver implements MentionResolver
{
    public function __construct(
        private string $template,
    ) {
        if (1 !== substr_count($template, '%s')) {
            throw new InvalidExtensionException('A mention URL template must contain exactly one "%s" placeholder.');
        }
    }

    public function resolve(Mention $mention): MentionTarget
    {
        return new MentionTarget(str_replace('%s', rawurlencode($mention->identifier), $this->template));
    }
}
