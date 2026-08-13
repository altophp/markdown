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

use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Extension\Inline\InlineParseContext;
use Alto\Markdown\Extension\Inline\InlineParser;
use Alto\Markdown\Extension\Inline\InlineParseResult;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MentionParser implements InlineParser
{
    public function __construct(
        private MentionDefinition $definition,
    ) {}

    public function triggerByte(): string
    {
        return $this->definition->prefix[0];
    }

    public function tryParse(InlineParseContext $context): ?InlineParseResult
    {
        $offset = $context->offset();
        if ($offset > 0 && 1 === preg_match('/[A-Z0-9_]/i', $context->slice($offset - 1, $offset))) {
            return null;
        }

        $remaining = $context->remaining();
        $prefix = $this->definition->prefix;
        if (!str_starts_with($remaining, $prefix)) {
            return null;
        }

        $identifierSource = substr($remaining, \strlen($prefix), $this->definition->maxIdentifierBytes + 1);
        if (1 !== preg_match($this->definition->compiledPattern(), $identifierSource, $matches)) {
            return null;
        }

        $identifier = $matches[0];
        if ('' === $identifier || \strlen($identifier) > $this->definition->maxIdentifierBytes) {
            return null;
        }

        $mention = new Mention($this->definition->type, $prefix, $identifier);
        $target = $this->definition->resolver->resolve($mention);
        if (null === $target) {
            return null;
        }

        $source = $prefix . $identifier;

        return new InlineParseResult(
            $offset + \strlen($source),
            new InlineNode(
                $target->label ?? $source,
                [
                    'identifier' => $identifier,
                    'prefix' => $prefix,
                    'title' => $target->title,
                    'type' => $this->definition->type,
                    'url' => $target->url,
                ],
            ),
        );
    }
}
