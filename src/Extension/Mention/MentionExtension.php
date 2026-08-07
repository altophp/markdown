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
use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\Inline\InlineLinkSemantics;
use Alto\Markdown\Extension\InlineExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class MentionExtension implements InlineExtensionInterface
{
    /**
     * @var list<MentionDefinition>
     */
    private array $definitions;

    public function __construct(MentionDefinition ...$definitions)
    {
        if ([] === $definitions) {
            throw new InvalidExtensionException('MentionExtension requires at least one mention definition.');
        }

        $types = [];
        foreach ($definitions as $definition) {
            if (isset($types[$definition->type])) {
                throw new InvalidExtensionException(\sprintf('Mention type "%s" is registered more than once.', $definition->type));
            }

            $types[$definition->type] = true;
        }

        $this->definitions = array_values($definitions);
    }

    public function name(): string
    {
        return 'mention';
    }

    public function inlines(): iterable
    {
        $output = new MentionOutput();

        foreach ($this->definitions as $definition) {
            yield new InlineDefinition(
                kind: $definition->type,
                parser: new MentionParser($definition),
                html: $output,
                markdown: $output,
                link: new InlineLinkSemantics('url', 'title'),
            );
        }
    }
}
