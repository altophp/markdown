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

namespace Alto\Markdown\Extension\FrontMatter;

use Alto\Markdown\Extension\AbstractExtension;
use Alto\Markdown\Extension\NodeKindBindings;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Parser\Block\FrontMatterParser;
use Alto\Markdown\Profile\Feature;

/**
 * Opaque front matter block: a document-level convention, not Markdown syntax.
 *
 * Front matter is not in the published GFM spec, so this extension belongs to
 * the github profile only, next to alerts. Core does not decode YAML or TOML
 * and must not depend on a parser for either (SPEC section 11.7).
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FrontMatterExtension extends AbstractExtension
{
    public const string BLOCK_KIND = 'frontmatter:block';

    /**
     * @param list<NodeKind> $nodeKinds
     */
    public function __construct(
        private readonly ?NodeKind $blockKind = null,
        array $nodeKinds = [],
    ) {
        parent::__construct(
            'frontmatter',
            [Feature::FrontMatter],
            ['block'],
            $nodeKinds,
        );
    }

    public function blockConstructs(): array
    {
        return null === $this->blockKind ? [] : [new FrontMatterParser($this->blockKind->id)];
    }

    public function bindNodeKinds(NodeKindBindings $bindings): self
    {
        return new self(
            $bindings->get(self::BLOCK_KIND),
            $bindings->all(),
        );
    }
}
