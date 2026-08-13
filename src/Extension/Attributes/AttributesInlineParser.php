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

namespace Alto\Markdown\Extension\Attributes;

use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class AttributesInlineParser implements InlineParser
{
    public function __construct(private AttributeListParser $parser) {}

    public function triggerByte(): string
    {
        return '{';
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        $parsed = $this->parser->parsePrefix($context->remaining());

        if (null === $parsed) {
            return null;
        }

        return new InlineParseResult(
            $context->offset() + $parsed->length,
            new InlineNode(''),
        );
    }
}
