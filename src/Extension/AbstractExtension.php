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

namespace Alto\Markdown\Extension;

use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Profile\Feature;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract class AbstractExtension implements FeatureExtensionInterface, NodeKindBindableExtensionInterface, NodeKindExtensionInterface, SyntaxExtensionInterface
{
    /**
     * @param list<Feature>  $features
     * @param list<string>   $nodeKindNames
     * @param list<NodeKind> $nodeKinds
     */
    public function __construct(
        private readonly string $name,
        private readonly array $features = [],
        private readonly array $nodeKindNames = [],
        private readonly array $nodeKinds = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return iterable<NodeKind>
     */
    public function nodeKinds(): iterable
    {
        return $this->nodeKinds;
    }

    public function features(): iterable
    {
        return $this->features;
    }

    public function nodeKindNames(): array
    {
        return $this->nodeKindNames;
    }

    public function blockConstructs(): array
    {
        return [];
    }

    public function inlineConstructs(): array
    {
        return [];
    }

    /**
     * @param list<NodeKind> $nodeKinds
     */
    public function withNodeKinds(array $nodeKinds): ExtensionInterface
    {
        return new CompiledExtension($this->name, $this->features, $nodeKinds);
    }

    public function bindNodeKinds(NodeKindBindings $bindings): ExtensionInterface
    {
        return $this->withNodeKinds($bindings->all());
    }
}
