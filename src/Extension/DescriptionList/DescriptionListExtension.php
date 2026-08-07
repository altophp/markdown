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

namespace Alto\Markdown\Extension\DescriptionList;

use Alto\Markdown\Extension\AbstractExtension;
use Alto\Markdown\Extension\NodeKindBindings;
use Alto\Markdown\Node\Kind\NodeKind;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class DescriptionListExtension extends AbstractExtension
{
    public const string LIST_KIND = 'description-list:list';
    public const string TERM_KIND = 'description-list:term';
    public const string DESCRIPTION_KIND = 'description-list:description';

    /**
     * @param list<NodeKind> $nodeKinds
     */
    public function __construct(
        private readonly ?NodeKind $listKind = null,
        private readonly ?NodeKind $termKind = null,
        private readonly ?NodeKind $descriptionKind = null,
        array $nodeKinds = [],
    ) {
        parent::__construct(
            'description-list',
            nodeKindNames: ['list', 'term', 'description'],
            nodeKinds: $nodeKinds,
        );
    }

    public function blockConstructs(): array
    {
        if (null === $this->listKind || null === $this->termKind || null === $this->descriptionKind) {
            return [];
        }

        return [
            new DescriptionListParser($this->listKind->id, $this->descriptionKind->id),
            new DescriptionParser($this->listKind->id, $this->termKind->id, $this->descriptionKind->id),
        ];
    }

    public function bindNodeKinds(NodeKindBindings $bindings): self
    {
        return new self(
            $bindings->get(self::LIST_KIND),
            $bindings->get(self::TERM_KIND),
            $bindings->get(self::DESCRIPTION_KIND),
            $bindings->all(),
        );
    }
}
