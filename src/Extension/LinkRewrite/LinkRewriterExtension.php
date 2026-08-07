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

namespace Alto\Markdown\Extension\LinkRewrite;

use Alto\Markdown\Extension\LinkDestinationRewriterExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LinkRewriterExtension implements LinkDestinationRewriterExtensionInterface
{
    public function __construct(private LinkRewriter $rewriter)
    {
    }

    public function name(): string
    {
        return 'link-rewriter';
    }

    public function linkDestinationRewriters(): iterable
    {
        yield $this->rewriter;
    }
}
