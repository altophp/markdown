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

use Alto\Markdown\Exception\UnknownNodeKindException;
use Alto\Markdown\Node\Kind\NodeKind;

/**
 * @internal built-in syntax binding compiled before parsing
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class NodeKindBindings
{
    /**
     * @var array<string, NodeKind>
     */
    private array $byName;

    /**
     * @param iterable<NodeKind> $nodeKinds
     *
     * @internal created by the profile compiler
     */
    public function __construct(iterable $nodeKinds)
    {
        $byName = [];

        foreach ($nodeKinds as $nodeKind) {
            $byName[$nodeKind->name] = $nodeKind;
        }

        $this->byName = $byName;
    }

    public function get(string $qualifiedName): NodeKind
    {
        return $this->byName[$qualifiedName]
            ?? throw new UnknownNodeKindException(\sprintf('Node kind "%s" was not bound.', $qualifiedName));
    }

    /**
     * @return list<NodeKind>
     *
     * @internal compatibility bridge for built-in extensions
     */
    public function all(): array
    {
        return array_values($this->byName);
    }
}
