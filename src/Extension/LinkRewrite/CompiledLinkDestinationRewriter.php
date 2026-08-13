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

use Alto\Markdown\Operation\InlineLinkSyntax;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class CompiledLinkDestinationRewriter
{
    /**
     * @param list<LinkDestinationRewriter> $rewriters
     */
    public function __construct(private array $rewriters) {}

    public function rewrite(
        string $kind,
        string $destination,
        ?SourceRange $range,
        string $source,
    ): string {
        ++Instrumentation::$linkDestinationRewrites;
        $context = new LinkDestinationContext($kind, $destination, $range, $source);

        foreach ($this->rewriters as $rewriter) {
            $destination = $rewriter->rewrite($context);
            InlineLinkSyntax::validateDestination($destination);
            $context = $context->withDestination($destination);
        }

        return $destination;
    }
}
