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

namespace Alto\Markdown\Extension\Embed;

use Alto\Markdown\Exception\ResourceTooLargeException;
use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockContinueResult;
use Alto\Markdown\Extension\Block\BlockParser;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockStartResult;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Parser\Block\RootOnlyBlockParser;
use Alto\Markdown\Resource\ResourceRequest;
use Alto\Markdown\Resource\ResourceResolver;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class EmbedParser implements BlockParser, RootOnlyBlockParser
{
    public function __construct(
        private ResourceResolver $resolver,
        private EmbedPolicy $policy,
    ) {
    }

    public function triggerBytes(): string
    {
        return 'hH';
    }

    public function tryStart(BlockStartContext $context): ?BlockStartResult
    {
        if ($context->paragraphOpen() || 0 !== $context->indentColumns()) {
            return null;
        }

        $first = $context->firstNonSpaceOffset();
        $lineEnd = $context->lineContentEndOffset();
        if (
            $first !== $context->lineStartOffset()
            || $lineEnd - $first > $this->policy->maxUrlBytes
        ) {
            return null;
        }

        $url = rtrim($context->slice($first, $lineEnd), " \t");
        if (!$this->policy->isValidUrl($url)) {
            return null;
        }

        $html = null;
        if ($this->policy->allowsUrl($url)) {
            $request = new ResourceRequest($url, 'embed');
            $resource = $this->resolver->resolve($request);
            $bytes = \strlen($resource->bytes);

            if ($bytes > $this->policy->maxHtmlBytes) {
                throw new ResourceTooLargeException($request, $this->policy->maxHtmlBytes, $bytes);
            }

            $html = $resource->bytes;
        }

        return new BlockStartResult(
            startOffset: $first,
            advanceOffset: $lineEnd,
            state: new BlockState([
                'url' => $url,
                'html' => $html,
            ]),
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        unset($context);

        return BlockContinueResult::notMatched();
    }
}
